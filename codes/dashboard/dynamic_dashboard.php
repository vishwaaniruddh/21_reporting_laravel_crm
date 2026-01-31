<?php date_default_timezone_set("Asia/Calcutta"); ?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Alert Count Distribution</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
    <style>
        body {
            background-color: #f4f4f9;
            font-family: Arial, sans-serif;
        }

        .header {
            background-color: #343a40;
            color: white;
            padding: 20px;
            text-align: center;
            border-bottom: 5px solid #007bff;
        }

        .container {
            margin-top: 20px;
        }

        .table-container {
            background-color: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .table-header,.table-footer {
            background-color: #007bff !important;
            color: white;
            font-size: 16px;
        }

        .table-hover tbody tr:hover {
            background-color: #f1f1f1;
        }

        .modal-content {
            border-radius: 8px;
        }

        .loading {
            text-align: center;
            margin-top: 20%;
        }
        .table-footer {
    background-color: #007bff; /* Same background color as header */
    color: white; /* Same text color as header */
    font-size: 16px; /* Same font size as header */
    font-weight: bold; /* Make the text bold to match header */
}

    </style>
</head>

<body>

    <?php
    $currentTime = date("H:i"); // Get the current time in the "H:i" format (24-hour clock)

    // Define shift time boundaries
    $shift1Start = "07:00";
    $shift2Start = "15:00";
    $shift3Start = "23:00";

    if ($currentTime >= $shift1Start && $currentTime < $shift2Start) {
        $shift = 1;
    } elseif ($currentTime >= $shift2Start && $currentTime < $shift3Start) {
        $shift = 2;
    } else {
        $shift = 3;
    }



    ?>
    <div class="header">
        <h1>23 Server Alert Count Distribution</h1>
    </div>

    <div class="container">
        <div id="loading" class="loading">
            <img src="assets/loader.gif" alt="Loading..." />
        </div>

        <div class="table-container">
            <table id="dashboardTable" class="table table-striped table-hover">
                <thead class="table-header">
                    <tr>
                        <th>Terminal</th>
                        <th>User</th>
                        <th>Open</th>
                        <th>Close</th>
                        <th>Total</th>
                        <th>Critical Open</th>
                        <th>Critical Close</th>
                        <th>Total Critical</th>
                    </tr>
                </thead>
                <tbody id="tableContent">
                    <!-- Data will be populated here -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="myModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl" role="document">
            <div class="modal-content">
                <!-- Modal content will be loaded here via AJAX -->
            </div>
        </div>
    </div>
    <script>
    function openModalWithData(terminal, status) {
        const modalContent = `
            <div class="modal-header">
                <h5 class="modal-title">${status} Details for Terminal ${terminal}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Loading data...</p>
            </div>
        `;
        $("#myModal .modal-content").html(modalContent);
        $("#myModal").modal("show");

        $.ajax({
            url: './getSingleDashboardData.php',
            type: 'GET',
            data: {
                terminal: terminal,
                status: status,
                shift: shift
            },
            success: function(data) {
                $("#myModal .modal-content .modal-body").html(data);
            },
            error: function() {
                $("#myModal .modal-content .modal-body").html("Failed to load data.");
            }
        });
    }

    $(document).on("click", "td[data-terminal][data-status]", function() {
        const terminal = $(this).data("terminal");
        const status = $(this).data("status");
        openModalWithData(terminal, status);
    });

    function hideLoading() {
        $("#loading").hide();
    }

    function showLoading() {
        $("#loading").show();
    }

    function fetchData(shift) {
        $.ajax({
            url: './getDashboardData.php',
            method: 'GET',
            dataType: 'json',
            data: { shift: shift },
            success: function(data) {
                hideLoading();
                updateTable(data);
            },
            error: function() {
                console.log('Error fetching data.');
            }
        });
    }
    function updateTable(data) {
    const tableContent = document.getElementById('tableContent');
    const existingRows = {};
    tableContent.querySelectorAll('tr').forEach(row => {
        const terminal = row.querySelector('td').textContent;
        if (terminal) {
            existingRows[terminal] = row;
        }
    });

    data.data.forEach(dataRow => {
        const terminal = dataRow.terminal;
        let row = existingRows[terminal];

        if (!row) {
            // Create new row if it doesn't exist
            row = document.createElement('tr');
            row.innerHTML = `
                <td>${dataRow.terminal}</td>
                <td>${dataRow.username}</td>
                <td data-terminal="${dataRow.terminal}" data-status="open">${dataRow.open}</td>
                <td data-terminal="${dataRow.terminal}" data-status="close">${dataRow.close}</td>
                <td data-terminal="${dataRow.terminal}" data-status="total">${dataRow.total}</td>
                <td data-terminal="${dataRow.terminal}" data-status="criticalopen">${dataRow.criticalopen}</td>
                <td data-terminal="${dataRow.terminal}" data-status="criticalClose">${dataRow.criticalClose}</td>
                <td data-terminal="${dataRow.terminal}" data-status="totalCritical">${dataRow.totalCritical}</td>
            `;
            tableContent.appendChild(row);
            existingRows[terminal] = row;
        } else {
            // Update existing row
            row.querySelector(`td[data-status="open"]`).textContent = dataRow.open;
            row.querySelector(`td[data-status="close"]`).textContent = dataRow.close;
            row.querySelector(`td[data-status="total"]`).textContent = dataRow.total;
            row.querySelector(`td[data-status="criticalopen"]`).textContent = dataRow.criticalopen;
            row.querySelector(`td[data-status="criticalClose"]`).textContent = dataRow.criticalClose;
            row.querySelector(`td[data-status="totalCritical"]`).textContent = dataRow.totalCritical;
        }
    });

    // Update totals
    const totalRow = tableContent.querySelector('.table-footer');
    if (totalRow) {
        totalRow.innerHTML = `
            <td></td>
            <td></td>
            <td>${data.grandtotalOpenAlerts}</td>
            <td>${data.grandtotalCloseAlerts}</td>
            <td>${data.grandtotalAlerts}</td>
            <td>${data.grandtoalCriticalOpen}</td>
            <td>${data.grandtotalCloseCriticalAlert}</td>
            <td>${data.grandtotalCritical}</td>
        `;
    } else {
        const newTotalRow = document.createElement('tr');
        newTotalRow.setAttribute('class', 'table-footer');
        newTotalRow.innerHTML = `
            <td></td>
            <td></td>
            <td>${data.grandtotalOpenAlerts}</td>
            <td>${data.grandtotalCloseAlerts}</td>
            <td>${data.grandtotalAlerts}</td>
            <td>${data.grandtoalCriticalOpen}</td>
            <td>${data.grandtotalCloseCriticalAlert}</td>
            <td>${data.grandtotalCritical}</td>
        `;
        tableContent.appendChild(newTotalRow);
    }
}


    const shift = '<?php echo $shift; ?>';
    setInterval(function() {
        // showLoading();
        fetchData(shift);
    }, 5000);
</script>

</body>

</html>
