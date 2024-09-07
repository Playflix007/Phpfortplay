<?php
error_reporting(0);
ini_set('display_errors', 0);
header("Cache-Control: max-age=20, public");

function fetchMPDManifest(string $url): ?string {
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-Forwarded-For: 59.178.72.184'
        ],
    ]);
    $content = curl_exec($curl);
    curl_close($curl);
    return $content !== false ? $content : null;
}

function extractPsshFromManifest(string $content, string $baseUrl): ?array {
    if (($xml = @simplexml_load_string($content)) === false) return null;
    foreach ($xml->Period->AdaptationSet as $set) {
        if ((string)$set['contentType'] === 'audio') {
            foreach ($set->Representation as $rep) {
                $template = $rep->SegmentTemplate ?? null;
                if ($template) {
                    $media = str_replace(['$RepresentationID$', '$Number$'], [(string)$rep['id'], (int)($template['startNumber'] ?? 0) + (int)($template->SegmentTimeline->S['r'] ?? 0)], $template['media']);
                    $url = "$baseUrl/dash/$media";
                    $context = stream_context_create([
                        'http' => ['method' => 'GET', 'header' => 'X-Forwarded-For: 59.178.72.184'],
                    ]);
                    if (($content = @file_get_contents($url, false, $context)) !== false) {
                        $hex = bin2hex($content);
                        $marker = "000000387073736800000000edef8ba979d64acea3c827dcd51d21ed000000";
                        if (($pos = strpos($hex, $marker)) !== false && ($end = strpos($hex, "0000", $pos + strlen($marker))) !== false) {
                            $psshHex = substr($hex, $pos, $end - $pos - 12);
                            $psshHex = str_replace("000000387073736800000000edef8ba979d64acea3c827dcd51d21ed00000018", "000000327073736800000000edef8ba979d64acea3c827dcd51d21ed00000012", $psshHex);
                            $kidHex = substr($psshHex, 68, 32);
                            return [
                                'pssh' => base64_encode(hex2bin($psshHex)),
                                'kid' => substr($kidHex, 0, 8) . "-" . substr($kidHex, 8, 4) . "-" . substr($kidHex, 12, 4) . "-" . substr($kidHex, 16, 4) . "-" . substr($kidHex, 20)
                            ];
                        }
                    }
                }
            }
        }
    }
    return null;
}

function fetchWidevineLicense(string $pssh): ?string {
    $licenseServerUrl = 'https://your-license-server-url';  // Replace with your Widevine license server URL
    $curl = curl_init($licenseServerUrl);
    
    // Prepare the POST request to the license server
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',  // Widevine license server expects this
            'Authorization: Bearer your_auth_token'    // Include any authorization if required
        ],
        CURLOPT_POSTFIELDS => base64_decode($pssh)  // Sending PSSH as binary in the POST request
    ]);
    
    // Execute the request and get the response
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    // Check if the response is valid
    if ($response !== false && $httpCode === 200) {
        return $response;  // This is the license key response
    }
    
    return null;
}

function getChannelInfo(string $id): array {
    $json = @file_get_contents('https://playflix007.github.io/Api-TataPlay-widewine/tataplay.json');
    $channels = $json !== false ? json_decode($json, true) : null;
    if ($channels === null) {
        exit;
    }
    foreach ($channels as $channel) {
        if ($channel['id'] == $id) return $channel;
    }
    exit;
}

$id = $_GET['id'] ?? exit;
$channelInfo = getChannelInfo($id);
$dashUrl = $channelInfo['streamData']['MPD='] ?? exit;
if (strpos($dashUrl, 'https://bpprod') !== 0) {
    header("Location: $dashUrl");
    exit;
}

$manifestContent = fetchMPDManifest($dashUrl) ?? exit;
$baseUrl = dirname($dashUrl);
$widevinePssh = extractPsshFromManifest($manifestContent, $baseUrl);

if ($widevinePssh) {
    // Fetch the Widevine license key
    $licenseKey = fetchWidevineLicense($widevinePssh['pssh']);
    
    if ($licenseKey) {
        // Return the license key in the response
        header("Content-Type: application/json");
        echo json_encode([
            'licenseKey' => base64_encode($licenseKey),  // Encode the key as base64 if needed
            'kid' => $widevinePssh['kid']
        ]);
        exit;
    } else {
        // Failed to fetch the license key
        exit('Failed to fetch Widevine license key.');
    }
} else {
    exit('Failed to extract PSSH.');
}
?>
