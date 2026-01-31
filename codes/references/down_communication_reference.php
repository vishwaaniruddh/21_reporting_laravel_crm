<?php
            $todayDate = date('Y-m-d');  // Format for comparison with `dc_date`
    
            // SQL query to fetch records
            $thissql = mysqli_query($conn, "SELECT * FROM down_communication");

            // Check if query was successful
            if ($thissql === false) {
                die("Query failed: " . mysqli_error($conn));
            }

            $sr = 1; // Initialize the serial number for the HTML table rows
            $workingCount = 0;
            $notWorkingCount = 0;

            // Process each record
            while ($thissql_result = mysqli_fetch_assoc($thissql)) {
                $dc_date = $thissql_result['dc_date'];  // Assuming `dc_date` is in `DATETIME` format or NULL
                $atmid = $thissql_result['atmid'];
                $panelid = $thissql_result['panel_id'];

                // Extract the date part from the `DATETIME` value
                $dc_date_only = is_null($dc_date) ? null : date('Y-m-d', strtotime($dc_date));

                // Compare the date part with today's date
                if ($dc_date_only === $todayDate) {
                    $workingCount++;
                } elseif ($dc_date_only !== $todayDate || is_null($dc_date)) {
                    $sql = mysqli_query($conn, "SELECT * FROM sites WHERE live='Y' AND server_ip=23 AND NewPanelID='$panelid'");

                    // Check if query was successful
                    if ($sql === false) {
                        die("Query failed: " . mysqli_error($conn));
                    }

                    if ($sqlResult = mysqli_fetch_assoc($sql)) {
                        // Extract site details
                        $Customer = $sqlResult['Customer'];
                        $Bank = $sqlResult['Bank'];
                        $ATMID = $sqlResult['ATMID'];
                        $ATMShortName = $sqlResult['ATMShortName'];
                        $City = $sqlResult['City'];
                        $state = $sqlResult['State'];
                        $panel_make = $sqlResult['Panel_Make'];
                        $OLDPanelid = $sqlResult['OldPanelID'];
                        $NewPanelID = $sqlResult['NewPanelID'];
                        $dvrip = $sqlResult['DVRIP'];
                        $dvrname = $sqlResult['DVRName'];
                        $remarkdate = $dc_date;
                        $Zone = $sqlResult['Zone'];

                        // Fetch BM name
                        $bmname = "SELECT CSSBM, CSSBMNumber FROM esurvsites WHERE ATM_ID='$ATMID'";
                        $runbmname = mysqli_query($conn, $bmname);

                        if ($runbmname === false) {
                            die("Query failed: " . mysqli_error($conn));
                        }

                        $bmfetch = mysqli_fetch_array($runbmname);

                        // Output HTML table row
                        echo '<tr style="background-color:#cfe8c7">';
                        echo "<td>$sr</td>";
                        echo "<td>$Customer</td>";
                        echo "<td>$Bank</td>";
                        echo "<td>$ATMID</td>";
                        echo "<td>$ATMShortName</td>";
                        echo "<td>$City</td>";
                        echo "<td>$state</td>";
                        echo "<td>$panel_make</td>";
                        echo "<td>$OLDPanelid</td>";
                        echo "<td>$NewPanelID</td>";
                        echo "<td>$dvrip</td>";
                        echo "<td>$dvrname</td>";
                        echo "<td>$remarkdate</td>";
                        echo "<td>{$bmfetch[0]}</td>";
                        echo "<td>{$bmfetch[1]}</td>";
                        echo "<td>$Zone</td>";
                        echo '</tr>';

                        $sr++; // Increment the serial number
                        $notWorkingCount++;
                    }
                }
            }
            ?>

            <div align="center">
                <strong>Total records: <?php echo $workingCount + $notWorkingCount; ?></strong>
                <hr>
                <span style="color:green;">Working ATMs: <?php echo $workingCount; ?></span>
                &nbsp;&nbsp;&nbsp;&nbsp;

                <span style="color:red;">Not Working ATMs: <?php echo $notWorkingCount; ?></span>
            </div>

        