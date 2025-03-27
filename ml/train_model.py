import os
import re
import pickle
import pandas as pd
from sqlalchemy import create_engine

# NLTK imports for lemmatization (no need for word_tokenize now)
import nltk
# Download only required resources for lemmatization
nltk.download('wordnet')
nltk.download('omw-1.4')

# Import our custom preprocessor from the separate module.
from preprocessing import custom_preprocessor

from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import train_test_split, GridSearchCV
from sklearn.metrics import classification_report, confusion_matrix

# imblearn pipeline for SMOTE and classifier
from imblearn.pipeline import Pipeline as ImbPipeline
from imblearn.over_sampling import SMOTE

def determine_type(desc):
    """
    Determine the transaction type based on the description.
    Returns 'CR' if 'CR' is found in the text, 'DR' if 'DR' is found,
    or defaults to 'DR' if neither is found.
    """
    desc_upper = desc.upper()
    if 'CR' in desc_upper:
        return 'CR'
    elif 'DR' in desc_upper:
        return 'DR'
    else:
        return 'DR'

def build_vectorizer():
    """
    Build a TfidfVectorizer that uses the custom preprocessor and English stopwords.
    """
    vectorizer = TfidfVectorizer(stop_words='english', preprocessor=custom_preprocessor)
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
    Build and train an imblearn pipeline for the category model using SMOTE and Logistic Regression.
    A grid search is performed to optimize hyperparameters.
    """
    pipeline = ImbPipeline([
        ('smote', SMOTE(random_state=42, k_neighbors=1)),
        ('clf', LogisticRegression(class_weight='balanced', max_iter=1000))
    ])

    # Define grid search parameters; expand this grid as needed.
    param_grid = {
        'clf__C': [0.1, 1, 10],
        'clf__solver': ['liblinear', 'saga']
    }

    grid = GridSearchCV(pipeline, param_grid, cv=3, n_jobs=-1, verbose=1)
    grid.fit(X_train, y_train)

    print("Best hyperparameters for category model:", grid.best_params_)
    return grid.best_estimator_

def train_type_model(X, y_type):
    """
    Train a simple Logistic Regression model for the type prediction.
    """
    model = LogisticRegression(class_weight='balanced', max_iter=1000, solver='liblinear')
    model.fit(X, y_type)
    return model

def main():
    # Pull MySQL connection parameters from environment variables.
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

    # Load data from MySQL into a DataFrame.
    data = load_data(engine)
    if data is None:
        return

    # Verify required columns exist.
    for col in ['description', 'category', 'type']:
        if col not in data.columns:
            print(f"Data must contain the '{col}' column")
            return

    # Use heuristic for 'type' if all values are null.
    if data['type'].isnull().all():
        data['type'] = data['description'].apply(determine_type)
        print("Added 'type' column based on description heuristic.")
    else:
        print("Using existing 'type' column for training.")

    print("Overall category distribution:")
    print(data['category'].value_counts())

    # Build and fit the TF-IDF vectorizer.
    vectorizer = build_vectorizer()
    try:
        X_all = vectorizer.fit_transform(data['description'])
    except Exception as e:
        print("Error during vectorizer fitting:", e)
        return

    print("Vectorizer idf shape:", vectorizer.idf_.shape)

    # -------------------- Train Category Model -------------------- #
    X_train, X_test, y_train, y_test = train_test_split(
        X_all, data['category'], test_size=0.2, random_state=42)

    print("Training data category distribution:")
    print(y_train.value_counts())

    # Filter out categories with fewer than 2 samples for SMOTE stability.
    valid_categories = y_train.value_counts()[y_train.value_counts() >= 2].index.tolist()
    valid_mask = y_train.isin(valid_categories).to_numpy()
    X_train_valid = X_train[valid_mask]
    y_train_valid = y_train[valid_mask]
    print("Categories with at least 2 samples:", valid_categories)

    # Train category model using pipeline with grid search.
    model_category = train_category_model(X_train_valid, y_train_valid)

    # Evaluate category model on the test set.
    y_pred = model_category.predict(X_test)
    print("Category Model - Confusion Matrix:")
    print(confusion_matrix(y_test, y_pred))
    print("Category Model - Classification Report:")
    print(classification_report(y_test, y_pred))

    # -------------------- Train Type Model -------------------- #
    y_type = data['type']
    model_type = train_type_model(X_all, y_type)

    # -------------------- Save Models and Vectorizer -------------------- #
    script_dir = os.path.dirname(os.path.abspath(__file__))
    model_category_path = os.path.join(script_dir, 'model_category.pkl')
    model_type_path = os.path.join(script_dir, 'model_type.pkl')
    vectorizer_path = os.path.join(script_dir, 'vectorizer.pkl')

    with open(model_category_path, 'wb') as f:
        pickle.dump(model_category, f)
    with open(model_type_path, 'wb') as f:
        pickle.dump(model_type, f)
    with open(vectorizer_path, 'wb') as f:
        pickle.dump(vectorizer, f)

    print("Models and vectorizer trained and saved successfully in:", script_dir)

if __name__ == '__main__':
    main()
