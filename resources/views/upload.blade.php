<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Upload Bank Statement PDF for OCR</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 2rem; }
    form { max-width: 500px; margin: auto; }
    input, select { display: block; margin-bottom: 1rem; width: 100%; }
    #pleaseWait { margin-top: 1rem; font-size: 1.2rem; color: #555; }
    #result { margin-top: 2rem; padding: 1rem; border: 1px solid #ccc; white-space: pre-wrap; }
  </style>
</head>
<body>
  <h1>Upload Bank Statement PDF</h1>
  <form id="uploadForm" action="" method="POST" enctype="multipart/form-data">
    @csrf
    <label for="pdfType">Select PDF Type:</label>
    <select id="pdfType" name="pdfType">
      <option value="public">Public Bank Statement</option>
      <option value="creditSense">Credit Sense Statement</option>
    </select>
    
    <label for="pdf">Select PDF:</label>
    <input type="file" id="pdf" name="pdf" required>
    
    <button type="submit">Upload and Process</button>
    <p id="pleaseWait" style="display: none;">Please wait...</p>
  </form>
  
  <div id="result" style="display: none;"></div>
  
  <script>
    // Update form action based on selection.
    const form = document.getElementById('uploadForm');
    const pdfTypeSelect = document.getElementById('pdfType');
    
    function updateFormAction() {
      const type = pdfTypeSelect.value;
      if (type === 'creditSense') {
        form.action = "{{ route('process-pdf-credit-sense') }}";
      } else {
        form.action = "{{ route('process-pdf-public') }}";
      }
    }
    
    // Set initial action and update on change.
    updateFormAction();
    pdfTypeSelect.addEventListener('change', updateFormAction);
    
    // Optional: AJAX submission
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      document.getElementById('pleaseWait').style.display = 'block';
      
      let formData = new FormData(form);
      fetch(form.action, {
        method: 'POST',
        body: formData,
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}' }
      })
      .then(response => response.json())
      .then(data => {
        document.getElementById('pleaseWait').style.display = 'none';
        document.getElementById('result').style.display = 'block';
        document.getElementById('result').innerText = JSON.stringify(data, null, 2);
      })
      .catch(error => {
        document.getElementById('pleaseWait').style.display = 'none';
        document.getElementById('result').style.display = 'block';
        document.getElementById('result').innerText = 'Error: ' + error;
      });
    });
  </script>
</body>
</html>
