import os
import pickle
import io
import csv
import torch
from flask import Flask, request, jsonify, send_file, Response
from reportlab.pdfgen import canvas
from transformers import AutoTokenizer, AutoModel

# Define paths based on current script directory
script_dir = os.path.dirname(os.path.abspath(__file__))
model_category_path = os.path.join(script_dir, 'model_category.pkl')
model_type_path = os.path.join(script_dir, 'model_type.pkl')
le_category_path = os.path.join(script_dir, 'le_category.pkl')
le_type_path = os.path.join(script_dir, 'le_type.pkl')
bert_save_dir = os.path.join(script_dir, 'bert')

# Load trained models and label encoders
with open(model_category_path, 'rb') as f:
    model_category = pickle.load(f)
with open(model_type_path, 'rb') as f:
    model_type = pickle.load(f)
with open(le_category_path, 'rb') as f:
    le_category = pickle.load(f)
with open(le_type_path, 'rb') as f:
    le_type = pickle.load(f)

# Load BERT tokenizer and model
tokenizer = AutoTokenizer.from_pretrained(bert_save_dir)
bert_model = AutoModel.from_pretrained(bert_save_dir)
device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
bert_model.to(device)
bert_model.eval()

def get_bert_embedding(text, batch_size=1):
    """
    Generate BERT embedding for the given text by averaging the token embeddings
    from the last hidden state.
    """
    encoded = tokenizer(
        text,
        padding=True,
        truncation=True,
        return_tensors="pt"
    )
    encoded = {k: v.to(device) for k, v in encoded.items()}
    with torch.no_grad():
        output = bert_model(**encoded)
    # Average pooling over the tokens (dim=1) to get a single vector
    embedding = output.last_hidden_state.mean(dim=1)
    return embedding.cpu().numpy()

app = Flask(__name__)

@app.route('/predict', methods=['POST'])
def predict():
    data = request.get_json(force=True)
    if 'text' not in data:
        return jsonify({'error': "Missing 'text' field in request data"}), 400

    description = data['text']
    # Generate BERT embedding for the input text
    X = get_bert_embedding(description)

    # Predict with probabilities using the BERT embeddings
    category_proba = model_category.predict_proba(X)[0]
    type_proba = model_type.predict_proba(X)[0]

    # Get numeric predictions from highest probability
    pred_category_num = model_category.classes_[category_proba.argmax()]
    pred_type_num = model_type.classes_[type_proba.argmax()]

    # Convert NumPy float32 values to native Python floats for JSON serialization
    confidence_category = float(max(category_proba))
    confidence_type = float(max(type_proba))

    # Convert numeric predictions back to string labels using the label encoders
    predicted_category = le_category.inverse_transform([pred_category_num])[0]
    predicted_type = le_type.inverse_transform([pred_type_num])[0]

    # Set uncertainty threshold
    threshold = 0.1
    if confidence_category < threshold:
        predicted_category = "Uncertain"
    if confidence_type < threshold:
        predicted_type = "Uncertain"

    return jsonify({
        'category': predicted_category,
        'category_confidence': confidence_category,
        'type': predicted_type,
        'type_confidence': confidence_type
    })

@app.route('/train', methods=['POST'])
def train():
    # Note: XGBoost models do not support incremental training via partial_fit.
    # Hence, this endpoint will return an error if invoked.
    return jsonify({'error': 'Incremental training is not supported for the current model.'}), 500

@app.route('/download/pdf')
def download_pdf():
    buffer = io.BytesIO()
    p = canvas.Canvas(buffer)
    p.setFont("Helvetica", 16)
    p.drawString(100, 750, "Bank Statement / Prediction Details")
    p.setFont("Helvetica", 12)
    p.drawString(100, 730, "Account Holder: John Doe")
    p.drawString(100, 710, "Account Number: 123456789")
    p.drawString(100, 690, "Statement Date: 2025-03-28")
    p.drawString(100, 670, "Closing Balance: $1,000.00")
    p.drawString(100, 650, "Predicted Category: Sample Category")
    p.drawString(100, 630, "Predicted Type: Sample Type")
    p.showPage()
    p.save()
    buffer.seek(0)
    return send_file(buffer, as_attachment=True, download_name='statement.pdf', mimetype='application/pdf')

@app.route('/download/csv')
def download_csv():
    output = io.StringIO()
    writer = csv.writer(output)
    writer.writerow(['Field', 'Value'])
    writer.writerow(['Account Holder', 'John Doe'])
    writer.writerow(['Account Number', '123456789'])
    writer.writerow(['Statement Date', '2025-03-28'])
    writer.writerow(['Closing Balance', '1000.00'])
    writer.writerow([])
    writer.writerow(['Date', 'Description', 'Category', 'Debit', 'Credit', 'Balance', 'Type'])
    writer.writerow(['2025-03-28', 'Transaction 1', 'Sample Category', '100.00', '0.00', '900.00', 'Debit'])
    writer.writerow(['2025-03-28', 'Transaction 2', 'Sample Category', '0.00', '150.00', '1050.00', 'Credit'])
    output.seek(0)
    return Response(output.getvalue(), mimetype='text/csv', headers={"Content-Disposition": "attachment;filename=statement.csv"})

if __name__ == '__main__':
    app.run(debug=True, host='0.0.0.0', port=5000)
