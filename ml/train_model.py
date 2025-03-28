import os
import pickle
import pandas as pd
from sqlalchemy import create_engine
import nltk
from collections import Counter
from sklearn.preprocessing import LabelEncoder
import numpy as np

# Download necessary NLTK resources
nltk.download('wordnet')
nltk.download('omw-1.4')

# Import custom preprocessor
from preprocessing import custom_preprocessor

from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.model_selection import train_test_split
from sklearn.metrics import classification_report, confusion_matrix
from imblearn.pipeline import Pipeline as ImbPipeline
from imblearn.over_sampling import SMOTE, RandomOverSampler
from xgboost import XGBClassifier  # XGBoost for better performance

def determine_type(desc):
    desc_upper = desc.upper()
    if 'CR' in desc_upper:
        return 'CR'
    elif 'DR' in desc_upper:
        return 'DR'
    else:
        return 'DR'

def build_vectorizer():
    """
    Builds a TF-IDF Vectorizer with character n-grams for better feature extraction.
    """
    vectorizer = TfidfVectorizer(
        stop_words='english',
        preprocessor=custom_preprocessor,
        ngram_range=(1, 3)  # Uses word-level and character-level n-grams
    )
    return vectorizer

def load_data(engine):
    query = "SELECT description, category, type FROM train_data"
    try:
        data = pd.read_sql(query, engine)
        return data
    except Exception as e:
        print("Error loading data from MySQL:", e)
        return None

def train_category_model(X_train, y_train):
    """
    Train an XGBoost model using an imbalanced-learn pipeline.
    This version first checks the class distribution. If any class has fewer than 2 samples,
    it uses RandomOverSampler; otherwise, it uses SMOTE.
    """
    category_counts = Counter(y_train)
    min_samples = min(category_counts.values())

    if min_samples < 2:
        print("Warning: Some classes have fewer than 2 samples. Using RandomOverSampler instead of SMOTE.")
        oversampler = RandomOverSampler(random_state=42)
    else:
        # Use SMOTE with k_neighbors adjusted to the smallest class size
        smote_neighbors = max(1, min_samples - 1)
        oversampler = SMOTE(random_state=42, k_neighbors=smote_neighbors)

    pipeline = ImbPipeline([
        ('oversample', oversampler),
        ('clf', XGBClassifier(
            use_label_encoder=False,
            eval_metric="mlogloss",
            objective='multi:softprob',
            n_estimators=100,
            learning_rate=0.1,
            max_depth=5
        ))
    ])

    pipeline.fit(X_train, y_train)
    return pipeline

def train_type_model(X, y_type):
    """
    Train an XGBoost model for predicting transaction type.
    """
    model = XGBClassifier(
        use_label_encoder=False,
        eval_metric="logloss",
        n_estimators=100,
        learning_rate=0.1,
        max_depth=5
    )
    model.fit(X, y_type)
    return model

def main():
    MYSQL_USER = os.environ.get("MYSQL_USER", "laraveluser")
    MYSQL_PASSWORD = os.environ.get("MYSQL_PASSWORD", "secret")
    MYSQL_HOST = os.environ.get("MYSQL_HOST", "localhost")
    MYSQL_PORT = os.environ.get("MYSQL_PORT", "3306")
    MYSQL_DB = os.environ.get("MYSQL_DB", "laravel")

    connection_string = (
        f"mysql+mysqlconnector://{MYSQL_USER}:{MYSQL_PASSWORD}@"
        f"{MYSQL_HOST}:{MYSQL_PORT}/{MYSQL_DB}?auth_plugin=mysql_native_password"
    )
    engine = create_engine(connection_string)

    data = load_data(engine)
    if data is None:
        return

    if 'type' not in data or data['type'].isnull().all():
        data['type'] = data['description'].apply(determine_type)
        print("Generated 'type' column based on description.")

    print("Category Distribution Before Training:")
    print(data['category'].value_counts())

    vectorizer = build_vectorizer()
    X_all = vectorizer.fit_transform(data['description'])

    # Encode category and type labels using LabelEncoder
    le_category = LabelEncoder()
    y_category = le_category.fit_transform(data['category'])
    le_type = LabelEncoder()
    y_type = le_type.fit_transform(data['type'])

    # Train-Test Split for category model
    X_train, X_test, y_train, y_test = train_test_split(
        X_all, y_category, test_size=0.2, random_state=42)

    # Train Category Model with XGBoost and an appropriate oversampler
    model_category = train_category_model(X_train, y_train)

    # Evaluate Category Model
    y_pred = model_category.predict(X_test)
    print("Category Model - Confusion Matrix:")
    print(confusion_matrix(y_test, y_pred))
    unique_labels = np.unique(y_test)
    target_names = le_category.inverse_transform(unique_labels)
    print("Category Model - Classification Report:")
    print(classification_report(y_test, y_pred, labels=unique_labels, target_names=target_names))

    # Train Type Model on full data
    model_type = train_type_model(X_all, y_type)

    # Save Models, Vectorizer, and Label Encoders
    script_dir = os.path.dirname(os.path.abspath(__file__))
    with open(os.path.join(script_dir, 'model_category.pkl'), 'wb') as f:
        pickle.dump(model_category, f)
    with open(os.path.join(script_dir, 'model_type.pkl'), 'wb') as f:
        pickle.dump(model_type, f)
    with open(os.path.join(script_dir, 'vectorizer.pkl'), 'wb') as f:
        pickle.dump(vectorizer, f)
    with open(os.path.join(script_dir, 'le_category.pkl'), 'wb') as f:
        pickle.dump(le_category, f)
    with open(os.path.join(script_dir, 'le_type.pkl'), 'wb') as f:
        pickle.dump(le_type, f)

    print("Models, vectorizer, and label encoders saved successfully.")

if __name__ == '__main__':
    main()

