<!DOCTYPE html>
<html>
<head>
    <script>
        window.location.href = `keyflow://enquiry/{{ $enquiryId }}`;
        setTimeout(() => {
            window.location.href = `https://usekeyflow.com/dashboard/enquiries/{{ $enquiryId }}/view`;
        }, 2500);
    </script>
</head>
<body>
    <p>Opening app, please wait...</p>
</body>
</html>
