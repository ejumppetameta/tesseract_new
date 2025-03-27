import os
import pickle
import io
import csv
from flask import Flask, request, jsonify, send_file, Response

# Import the custom preprocessor so it is available when unpickling the vectorizer.
from preprocessing import custom_preprocessor

app = Flask(__name__)

# Determine the directory where api.py resides
script_dir = os.path.dirname(os.path.abspath(__file__))

# Define paths for the saved models and vectorizer
model_category_path = os.path.join(script_dir, 'model_category.pkl')
model_type_path = os.path.join(script_dir, 'model_type.pkl')
vectorizer_path = os.path.join(script_dir, 'vectorizer.pkl')

# Load the trained models and vectorizer
with open(model_category_path, 'rb') as f:
    model_category = pickle.load(f)
with open(model_type_path, 'rb') as f:
    model_type = pickle.load(f)
with open(vectorizer_path, 'rb') as f:
    vectorizer = pickle.load(f)

@app.route('/predict', methods=['POST'])
def predict():
    # Expect JSON input with a "text" field containing the description
    data = request.get_json(force=True)
    if 'text' not in data:
        return jsonify({'error': "Missing 'text' field in request data"}), 400

    description = data['text']
    # Transform the description using the vectorizer
    X = vectorizer.transform([description])
    # Predict using the loaded models
    predicted_category = model_category.predict(X)[0]
    predicted_type = model_type.predict(X)[0]

    return jsonify({
        'category': predicted_category,
        'type': predicted_type
    })

@app.route('/download/pdf')
def download_pdf():
    # Create an in-memory bytes buffer for the PDF
    buffer = io.BytesIO()
    # Create a PDF canvas using ReportLab
    from reportlab.pdfgen import canvas
    p = canvas.Canvas(buffer)
    p.setFont("Helvetica", 16)
    p.drawString(100, 750, "Bank Statement / Prediction Details")

    # Customize the PDF content as needed
    p.setFont("Helvetica", 12)
    p.drawString(100, 730, "Account Holder: John Doe")
    p.drawString(100, 710, "Account Number: 123456789")
    p.drawString(100, 690, "Statement Date: 2023-01-01")
    p.drawString(100, 670, "Closing Balance: $1,000.00")
    # Example: display static prediction details
    p.drawString(100, 650, "Predicted Category: Sample Category")
    p.drawString(100, 630, "Predicted Type: Sample Type")

    p.showPage()
    p.save()
    buffer.seek(0)

    # Send the PDF file as a downloadable attachment
    return send_file(buffer, as_attachment=True, download_name='statement.pdf', mimetype='application/pdf')

@app.route('/download/csv')
def download_csv():
    # Create an in-memory string buffer for CSV data
    output = io.StringIO()
    writer = csv.writer(output)

    # Optional: Write statement header details
    writer.writerow(['Field', 'Value'])
    writer.writerow(['Account Holder', 'John Doe'])
    writer.writerow(['Account Number', '123456789'])
    writer.writerow(['Statement Date', '2023-01-01'])
    writer.writerow(['Closing Balance', '1000.00'])
    writer.writerow([])  # Empty row for separation

    # CSV header for transactions or prediction details
    writer.writerow(['Date', 'Description', 'Category', 'Debit', 'Credit', 'Balance', 'Type'])
    # Example row data; replace with dynamic data if needed
    writer.writerow(['2023-01-01', 'Transaction 1', 'Sample Category', '100.00', '0.00', '900.00', 'Debit'])
    writer.writerow(['2023-01-02', 'Transaction 2', 'Sample Category', '0.00', '150.00', '1050.00', 'Credit'])

    output.seek(0)
    return Response(output.getvalue(),
                    mimetype='text/csv',
                    headers={"Content-Disposition": "attachment;filename=statement.csv"})

if __name__ == '__main__':
    # Run the Flask app on port 5000 and listen on all interfaces
    app.run(debug=True, host='0.0.0.0', port=5000)
