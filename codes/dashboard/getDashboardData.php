<?php
include('config.php');

$data = array(); // Create an array to store the data

$grandtotalOpenAlerts = 0;
$grandtotalCloseAlerts = 0;
$grandtotalAlerts = 0;
$grandtoalCriticalOpen = 0;
$grandtotalCloseCriticalAlert = 0;
$grandtotalCritical = 0;

$date = date('Y-m-d');
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

$Shift = $_REQUEST['shift'];

if ($Shift == "1") {
    $ShiftWise = " '$date 07:00:00' and '$date 14:59:59' ";
} else if ($Shift == "2") {
    $ShiftWise = " '$date 15:00:00' and '$date 22:59:59' ";
} else if ($Shift == "3") {
    $ShiftWise = " '$date 23:00:00' and '$nextDay 06:59:59' ";
}

$allCriticalAlerts = array(
    'Panic Button', 'ATM Hood door', 'ATM2 Chest Door', 'ATM1 and 2 Hood Door', 'Panic Switch',
    'ATM1 Chest Door Sensor', 'ATM 2 Chest Door Open', 'Unauthorize Card', 'ATM3 Chest Door Open', 'ATM2 Chest Door Open', 'ATM1 Chest Door Open',
    'ATM1 Chest door', 'ATM 2 Hood Door Sensor', 'ATM 1 Hood Door Sensor', 'ATM 1 Vibration'
);


// Fetch terminals and users from alertscount table
$sql = mysqli_query($conn, "SELECT ip AS terminal, userid FROM alertscount WHERE status=1");

while ($sqlResult = mysqli_fetch_assoc($sql)) {
    $terminal = $sqlResult['terminal'];
    $userid = $sqlResult['userid'];

    // Fetch username from loginusers table
    $getuser = mysqli_query($conn, "SELECT name FROM loginusers WHERE id ='" . $userid . "'");
    $getuserResult = mysqli_fetch_assoc($getuser);
    $username = $getuserResult['name'];

    // Query to fetch open and close alerts, including critical alerts
    $alertSql = "
        SELECT 
            SUM(CASE WHEN status LIKE 'O' THEN 1 ELSE 0 END) AS openAlerts,
            SUM(CASE WHEN status LIKE 'C' THEN 1 ELSE 0 END) AS closeAlerts,
            SUM(CASE WHEN status LIKE 'O' AND critical_alerts='y' THEN 1 ELSE 0 END) AS criticalOpen,
            SUM(CASE WHEN status LIKE 'C' AND critical_alerts='y' THEN 1 ELSE 0 END) AS criticalClose
        FROM alerts
        WHERE sendtoclient='S'
        AND (sendip='$terminal' OR sip2='$terminal')
        AND receivedtime BETWEEN $ShiftWise
    ";

    $alertResult = mysqli_query($conn, $alertSql);
    $alertData = mysqli_fetch_assoc($alertResult);

    $totalOpenAlerts = $alertData['openAlerts'];
    $totalCloseAlerts = $alertData['closeAlerts'];
    $totalAlerts = $totalOpenAlerts + $totalCloseAlerts;
    
    $totalCriticalOpen = $alertData['criticalOpen'];
    $totalCloseCriticalAlert = $alertData['criticalClose'];
    $totalCritical = $totalCriticalOpen + $totalCloseCriticalAlert;

    // Prepare data for each terminal
    $data[] = array(
        'username' => ucwords($username),
        'terminal' => $terminal,
        'open' => $totalOpenAlerts,
        'close' => $totalCloseAlerts,
        'total' => $totalAlerts,
        'criticalopen' => $totalCriticalOpen,
        'criticalClose' => $totalCloseCriticalAlert,
        'totalCritical' => $totalCritical,
    );

    // Accumulate grand totals
    $grandtotalOpenAlerts += $totalOpenAlerts;
    $grandtotalCloseAlerts += $totalCloseAlerts;
    $grandtotalAlerts += $totalAlerts;
    $grandtoalCriticalOpen += $totalCriticalOpen;
    $grandtotalCloseCriticalAlert += $totalCloseCriticalAlert;
    $grandtotalCritical += $totalCritical;
}

$total = [
    'data' => $data,
    'grandtotalOpenAlerts' => $grandtotalOpenAlerts,
    'grandtotalCloseAlerts' => $grandtotalCloseAlerts,
    'grandtotalAlerts' => $grandtotalAlerts,
    'grandtoalCriticalOpen' => $grandtoalCriticalOpen,
    'grandtotalCloseCriticalAlert' => $grandtotalCloseCriticalAlert,
    'grandtotalCritical' => $grandtotalCritical
];

// Return the data as JSON
header('Content-Type: application/json');
echo json_encode($total);
?>
