<?php
/**
 * Twilio SMS Test Script for PeacePing
 * 
 * This script tests sending SMS messages via Twilio API
 * 
 * Configuration:
 * - Set your Twilio Account SID and Auth Token
 * - Set your Twilio phone number
 * - Set the recipient phone number
 * - Set the message content
 */

// Twilio Configuration
$twilioAccountSid = 'ACb033286e609e64c337bdda0f7462157f';
$twilioAuthToken = '45880337b2338823d45a39c7cf903a32';
$twilioPhoneNumber = 'PeacePing'; // Format: +1234567890

// Test Configuration
$recipientPhone = '+447958198637'; // Test recipient phone number
$message = 'This is a test SMS from PeacePing via Twilio. Sender ID: PeacePing';

// Twilio API Endpoint
$url = "https://api.twilio.com/2010-04-01/Accounts/{$twilioAccountSid}/Messages.json";

// Prepare the data
$data = array(
    'From' => $twilioPhoneNumber, // You can also use a Twilio phone number here
    'To' => $recipientPhone,
    'Body' => $message,
);

// If you want to use alphanumeric sender ID instead of Twilio phone number
// Uncomment the line below and comment out the 'From' => $twilioPhoneNumber line
// Note: Alphanumeric sender IDs only work in certain countries
// $data['From'] = 'PeacePing';

// Initialize cURL
$ch = curl_init($url);

// Set cURL options
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_USERPWD, $twilioAccountSid . ':' . $twilioAuthToken);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

// Execute the request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

// Close cURL
curl_close($ch);

// Display results
echo "<h2>Twilio SMS Test</h2>";
echo "<p><strong>Recipient:</strong> {$recipientPhone}</p>";
echo "<p><strong>Message:</strong> {$message}</p>";
echo "<p><strong>HTTP Status Code:</strong> {$httpCode}</p>";

if ($curlError) {
    echo "<p style='color: red;'><strong>cURL Error:</strong> {$curlError}</p>";
}

echo "<h3>Response:</h3>";
echo "<pre>";
echo htmlspecialchars($response);
echo "</pre>";

// Parse JSON response
$responseData = json_decode($response, true);

if ($httpCode == 201 && isset($responseData['sid'])) {
    echo "<p style='color: green;'><strong>Success!</strong> Message SID: {$responseData['sid']}</p>";
    echo "<p><strong>Status:</strong> {$responseData['status']}</p>";
} else {
    echo "<p style='color: red;'><strong>Failed to send SMS</strong></p>";
    if (isset($responseData['message'])) {
        echo "<p><strong>Error Message:</strong> {$responseData['message']}</p>";
    }
}
?>
