import os
import pickle
import pandas as pd
import numpy as np
import nltk
from collections import Counter
from sqlalchemy import create_engine
from sklearn.preprocessing import LabelEncoder
from sklearn.model_selection import StratifiedShuffleSplit
from sklearn.metrics import classification_report, confusion_matrix, precision_recall_curve, average_precision_score
from sklearn.utils.class_weight import compute_class_weight
from imblearn.pipeline import Pipeline as ImbPipeline
from imblearn.over_sampling import SMOTE, RandomOverSampler
from xgboost import XGBClassifier
import matplotlib.pyplot as plt
import torch
from transformers import AutoTokenizer, AutoModel

# Download necessary NLTK resources
nltk.download('wordnet')
nltk.download('omw-1.4')

# Import custom preprocessor if needed (not used in BERT but kept for compatibility)
from preprocessing import custom_preprocessor

def determine_type(desc):
    desc_upper = desc.upper()
    return 'CR' if 'CR' in desc_upper else 'DR'

def load_data(engine):
    query = "SELECT description, category, type FROM train_data"
    try:
        return pd.read_sql(query, engine)
    except Exception as e:
        print("Error loading data from MySQL:", e)
        return None

def filter_small_categories(data, min_samples=2):
    """
    Removes categories that have fewer than the specified number of samples.
    """
    category_counts = data['category'].value_counts()
    small_categories = category_counts[category_counts < min_samples].index
    filtered_data = data[~data['category'].isin(small_categories)]
    print(f"Filtered out {len(small_categories)} small categories: {small_categories}")
    return filtered_data

def get_bert_embeddings(texts, tokenizer, model, device, batch_size=16):
    """
    Generates BERT embeddings for a list of texts.
    For each text, the embedding is computed by averaging the token embeddings
    from the last hidden state.
    """
    embeddings = []
    model.eval()  # Set model to evaluation mode
    with torch.no_grad():
        for i in range(0, len(texts), batch_size):
            batch_texts = texts[i:i+batch_size]
            # Tokenize the batch (using truncation and padding)
            encoded_inputs = tokenizer(batch_texts, padding=True, truncation=True, return_tensors="pt")
            encoded_inputs = {k: v.to(device) for k, v in encoded_inputs.items()}
            outputs = model(**encoded_inputs)
            # Average the token embeddings for each sample
            batch_embeddings = outputs.last_hidden_state.mean(dim=1)
            embeddings.append(batch_embeddings.cpu().numpy())
    return np.vstack(embeddings)

def train_category_model(X_train, y_train):
    category_counts = Counter(y_train)
    min_samples = min(category_counts.values())

    # Choose oversampling strategy based on class distribution
    oversampler = RandomOverSampler(random_state=42) if min_samples < 2 else SMOTE(random_state=42, k_neighbors=max(1, min_samples - 1))

    # Compute class weights for the imbalance
    class_weights = compute_class_weight('balanced', classes=np.unique(y_train), y=y_train)
    weight_dict = {i: class_weights[i] for i in range(len(class_weights))}

    pipeline = ImbPipeline([('oversample', oversampler),
                              ('clf', XGBClassifier(
                                  use_label_encoder=False,
                                  eval_metric="mlogloss",
                                  objective='multi:softprob',
                                  n_estimators=200,
                                  learning_rate=0.05,
                                  max_depth=6,
                                  scale_pos_weight=weight_dict  # Pass the class weights here
                              ))])
    pipeline.fit(X_train, y_train)
    return pipeline

def train_type_model(X, y_type):
    return XGBClassifier(
        use_label_encoder=False,
        eval_metric="logloss",
        n_estimators=100,
        learning_rate=0.1,
        max_depth=5
    ).fit(X, y_type)

def plot_precision_recall_curve(y_true, y_pred_proba, classes):
    """Plot precision-recall curve for each class."""
    for i, class_name in enumerate(classes):
        precision, recall, _ = precision_recall_curve(y_true == i, y_pred_proba[:, i])
        avg_precision = average_precision_score(y_true == i, y_pred_proba[:, i])
        plt.plot(recall, precision, label=f'{class_name} (AP={avg_precision:.2f})')

    plt.xlabel('Recall')
    plt.ylabel('Precision')
    plt.title('Precision-Recall Curve')
    plt.legend(loc='best')
    plt.show()

def main():
    MYSQL_USER = os.getenv("MYSQL_USER", "laraveluser")
    MYSQL_PASSWORD = os.getenv("MYSQL_PASSWORD", "secret")
    MYSQL_HOST = os.getenv("MYSQL_HOST", "localhost")
    MYSQL_PORT = os.getenv("MYSQL_PORT", "3306")
    MYSQL_DB = os.getenv("MYSQL_DB", "laravel")

    connection_string = f"mysql+mysqlconnector://{MYSQL_USER}:{MYSQL_PASSWORD}@{MYSQL_HOST}:{MYSQL_PORT}/{MYSQL_DB}?auth_plugin=mysql_native_password"
    engine = create_engine(connection_string)

    # Load data from database
    data = load_data(engine)
    if data is None:
        return

    if 'type' not in data or data['type'].isnull().all():
        data['type'] = data['description'].apply(determine_type)
        print("Generated 'type' column based on description.")

    print("Category Distribution Before Training:")
    print(data['category'].value_counts())

    # Filter out categories with too few samples
    data = filter_small_categories(data, min_samples=2)

    # Set up device and load BERT model/tokenizer
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    tokenizer = AutoTokenizer.from_pretrained("bert-base-uncased")
    model = AutoModel.from_pretrained("bert-base-uncased")
    model.to(device)

    # Generate BERT embeddings for all descriptions
    print("Generating BERT embeddings...")
    descriptions = data['description'].tolist()
    X_all = get_bert_embeddings(descriptions, tokenizer, model, device)

    # Encode labels
    le_category, le_type = LabelEncoder(), LabelEncoder()
    y_category = le_category.fit_transform(data['category'])
    y_type = le_type.fit_transform(data['type'])

    # Stratified split for ensuring balanced class distribution for category model training
    sss = StratifiedShuffleSplit(n_splits=1, test_size=0.2, random_state=42)
    for train_index, test_index in sss.split(X_all, y_category):
        X_train, X_test = X_all[train_index], X_all[test_index]
        y_train, y_test = y_category[train_index], y_category[test_index]

    # Train category model using BERT embeddings as features
    model_category = train_category_model(X_train, y_train)
    y_pred = model_category.predict(X_test)

    print("Category Model - Confusion Matrix:")
    print(confusion_matrix(y_test, y_pred))
    target_names = le_category.inverse_transform(np.unique(y_test))
    print("Category Model - Classification Report:")
    print(classification_report(y_test, y_pred, labels=np.unique(y_test), target_names=target_names, zero_division=0))

    # Get prediction probabilities for precision-recall curve and plot it
    y_pred_proba = model_category.predict_proba(X_test)
    plot_precision_recall_curve(y_test, y_pred_proba, target_names)

    # Train type model on all BERT embeddings
    model_type = train_type_model(X_all, y_type)

    # Save models, encoders, and also the BERT tokenizer and model for later use
    script_dir = os.path.dirname(os.path.abspath(__file__))
    objects_to_save = [
        ('model_category.pkl', model_category),
        ('model_type.pkl', model_type),
        ('le_category.pkl', le_category),
        ('le_type.pkl', le_type)
    ]
    for name, obj in objects_to_save:
        with open(os.path.join(script_dir, name), 'wb') as f:
            pickle.dump(obj, f)

    # Save BERT model and tokenizer using Hugging Face's save_pretrained method
    bert_save_dir = os.path.join(script_dir, 'bert')
    os.makedirs(bert_save_dir, exist_ok=True)
    model.save_pretrained(bert_save_dir)
    tokenizer.save_pretrained(bert_save_dir)

    print("Models, label encoders, and BERT components saved successfully.")

if __name__ == '__main__':
    main()
