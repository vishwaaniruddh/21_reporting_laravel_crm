<?php include('config.php');

$terminal = $_REQUEST['terminal'];
$status = $_REQUEST['status'];
$shift = $_REQUEST['shift'];

$date = date('Y-m-d');
$nextDay = date('Y-m-d', strtotime($date . ' +1 day'));

switch ($shift) {
    case "1":
        $ShiftWise = "'$date 07:00:00' AND '$date 14:59:59'";
        break;
    case "2":
        $ShiftWise = "'$date 15:00:00' AND '$date 22:59:59'";
        break;
    case "3":
        $ShiftWise = "'$date 23:00:00' AND '$nextDay 06:59:59'";
        break;
    default:
        $ShiftWise = "''";
}

$allCriticalAlerts = [
    'ATM Removal', 'Hood Door Open Sensor', 'Chest Door Open Sensor',
    'ATM Vibration', 'Panic Alert', 'Emergency Switch Panic Switch', 'Panic Button',
    'ATM Hood door', 'ATM2 Chest Door', 'ATM1 and 2 Hood Door', 'Panic Switch',
    'ATM1 Chest Door Sensor', 'ATM 2 Chest Door Open', 'Unauthorize Card',
    'ATM3 Chest Door Open', 'ATM2 Chest Door Open', 'ATM1 Chest Door Open',
    'ATM1 Chest door', 'ATM 2 Hood Door Sensor', 'ATM 1 Hood Door Sensor', 'ATM 1 Vibration'
];

$conditions = "a.sendtoclient='S' AND a.receivedtime BETWEEN $ShiftWise";
$criticalCondition = " AND a.critical_alerts='y'";

switch ($status) {
    case 'open':
        $statusCondition = "AND a.status='O'";
        break;
    case 'close':
        $statusCondition = "AND a.status='C'";
        break;
    case 'total':
        $statusCondition = "AND a.status IN ('O', 'C')";
        break;
    case 'criticalopen':
        $statusCondition = "AND a.status='O' $criticalCondition";
        break;
    case 'criticalClose':
        $statusCondition = "AND a.status='C' $criticalCondition";
        break;
    case 'totalCritical':
        $statusCondition = "AND a.status IN ('O', 'C') $criticalCondition";
        break;
    default:
        $statusCondition = '';
}

$alertSql = "
    SELECT a.*, s.ATMID, s.Zone, s.City
    FROM `alerts` a
    LEFT JOIN `sites` s ON s.OldPanelID = a.panelid OR s.NewPanelID = a.panelid
    WHERE ($conditions AND a.sendip='$terminal' $statusCondition)
       OR ($conditions AND a.sip2='$terminal' $statusCondition)
    ORDER BY a.receivedtime
";

$result = mysqli_query($conn, $alertSql);
?>

<table border="1">
    <thead>
        <tr>
            <th>Sr no</th>
            <th>ATMID</th>
            <th>Panel ID</th>
            <th>Zone</th>
            <th>City</th>
            <th>Received At</th>
            <th>Alert Type</th>
            <th>Comment</th>
            <th>Close By</th>
            <th>Closed At</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $i = 1;
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<tr>';
            echo '<td>' . $i++ . '</td>';
            echo '<td>' . $row['ATMID'] . '</td>';
            echo '<td>' . $row['panelid'] . '</td>';
            echo '<td>' . $row['Zone'] . '</td>';
            echo '<td>' . $row['City'] . '</td>';
            echo '<td>' . $row['receivedtime'] . '</td>';
            echo '<td>' . $row['alerttype'] . '</td>';
            echo '<td>' . $row['comment'] . '</td>';
            echo '<td>' . $row['closedBy'] . '</td>';
            echo '<td>' . $row['closedtime'] . '</td>';
            echo '</tr>';
        }
        ?>
    </tbody>
</table>
