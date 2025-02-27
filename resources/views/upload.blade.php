<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload PDF for OCR</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; }
        form { max-width: 500px; margin: auto; }
        input[type="file"] { display: block; margin-bottom: 1rem; }
        #pleaseWait { margin-top: 1rem; font-size: 1.2rem; color: #555; }
    </style>
</head>
<body>
    <h1>Upload Bank Statement PDF</h1>
    <form id="uploadForm" action="/process-pdf" method="POST" enctype="multipart/form-data">
        @csrf
        <label for="pdf">Select PDF:</label>
        <input type="file" id="pdf" name="pdf" required>
        <button type="submit">Upload and Process</button>
        <p id="pleaseWait" style="display: none;">Please wait...</p>
    </form>

    <script>
        document.getElementById('uploadForm').addEventListener('submit', function(){
            // Show the "Please wait..." message when the form is submitted
            document.getElementById('pleaseWait').style.display = 'block';
        });
    </script>
</body>
</html>
