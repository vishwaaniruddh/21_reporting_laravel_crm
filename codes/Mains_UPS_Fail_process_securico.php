<?php
session_start();
if (isset($_SESSION['login_user']) && isset($_SESSION['id'])) {
	set_time_limit(3600);
	include 'config.php';

	//$viewalert=$_POST['viewalert'];
	$panelid = $_POST['panelid'];
	$ATMID = $_POST['ATMID'];
	$DVRIP = $_POST['DVRIP'];
	$compy = $_POST['compy'];
	$from = $_POST['from'];
	$to = $_POST['to'];
	$strPage = $_POST['Page'];
	$fix = 670;

	function endsWith($haystack, $needle)
	{
		$length = strlen($needle);

		return $length === 0 ||
			(substr($haystack, -$length) === $needle);
	}

	if ($from != "") {
		//$newDate = date_format($date,"y/m/d H:i:s");
		$fromdt = date("Y-m-d", strtotime($from));
	} else {
		$fromdt = "";
	}
	if ($to != "") {
		$todt = date("Y-m-d", strtotime($to));
	} else {
		$todt = "";
	}

	$sr = 1;




	$abc =  "SELECT   a.Customer,a.Bank,a.ATMID,a.ATMShortName,a.SiteAddress,a.DVRIP,a.Panel_make,a.zone as zon,a.City,a.State,b.id,b.panelid,b.createtime,b.receivedtime,b.comment,b.zone,b.alarm,b.closedBy,b.closedtime FROM sites a,`alerts_acup` b WHERE (a.OldPanelID=b.panelid or a.NewPanelID=b.panelid) and b.zone IN ('029','030') and b.alarm='AT' ";
	//echo $abc; 

	$abc2 = "SELECT  a.Customer,a.Bank,a.ATMID,a.ATMShortName,a.SiteAddress,a.DVRIP,a.Panel_make,a.zone as zon,a.City,a.State,b.id,b.panelid,b.createtime,b.receivedtime,b.comment,b.zone,b.alarm,b.closedBy,b.closedtime FROM sites a,`alerts_acup` b WHERE (a.OldPanelID=b.panelid or a.NewPanelID=b.panelid) and b.zone IN ('001','002')and a.panel_make='SMART -I' and b.alarm='BA'";
	$abc3 = "SELECT  a.Customer,a.Bank,a.ATMID,a.ATMShortName,a.SiteAddress,a.DVRIP,a.Panel_make,a.zone as zon,a.City,a.State,b.id,b.panelid,b.createtime,b.receivedtime,b.comment,b.zone,b.alarm,b.closedBy,b.closedtime FROM sites a,`alerts_acup` b WHERE (a.OldPanelID=b.panelid or a.NewPanelID=b.panelid) and b.zone IN ('551','552')and (a.Panel_Make = 'securico_gx4816' OR a.Panel_Make = 'sec_sbi') and b.alarm ='BA'";
	$abc4 = "SELECT  a.Customer,a.Bank,a.ATMID,a.ATMShortName,a.SiteAddress,a.DVRIP,a.Panel_make,a.zone as zon,a.City,a.State,b.id,b.panelid,b.createtime,b.receivedtime,b.comment,b.zone,b.alarm,b.closedBy,b.closedtime FROM sites a,`alerts_acup` b WHERE (a.OldPanelID=b.panelid or a.NewPanelID=b.panelid) and b.zone IN ('027','028')and a.Panel_Make = 'SEC' and b.alarm ='BA'";



	if ($panelid != "") {
		$abc .= " and b.panelid='" . $panelid . "'";
		$abc2 .= " and b.panelid='" . $panelid . "'";
		$abc3 .= " and b.panelid='" . $panelid . "'";
		$abc4 .= " and b.panelid='" . $panelid . "'";
	}

	if ($ATMID != "") {
		$abc .= " and a.ATMID='" . $ATMID . "'";
		$abc2 .= " and a.ATMID='" . $ATMID . "'";
		$abc3 .= " and a.ATMID='" . $ATMID . "'";
		$abc4 .= " and a.ATMID='" . $ATMID . "'";
	}

	if ($DVRIP != "") {
		$abc .= " and a.DVRIP='" . $DVRIP . "'";
		$abc2 .= " and a.DVRIP='" . $DVRIP . "'";
		$abc3 .= " and a.DVRIP='" . $DVRIP . "'";
		$abc4 .= " and a.DVRIP='" . $DVRIP . "'";
	}
	if ($compy != "") {
		$abc .= " and a.Customer='" . $compy . "'";
		$abc2 .= " and a.Customer='" . $compy . "'";
		$abc3 .= " and a.Customer='" . $compy . "'";
		$abc4 .= " and a.Customer='" . $compy . "'";
	}


	if ($fromdt != "" && $todt !== "") {
		$abc .= " and b.receivedtime between '" . $fromdt . "' and '" . $todt . "' order by createtime ";
		$abc2 .= " and b.receivedtime between '" . $fromdt . "' and '" . $todt . "' order by createtime";
		$abc3 .= " and b.receivedtime between '" . $fromdt . "' and '" . $todt . "' order by createtime";
		$abc4 .= " and b.receivedtime between '" . $fromdt . "' and '" . $todt . "' order by createtime";
	} else if ($fromdt != "") {
		$abc .= " and b.receivedtime='" . $fromdt . "' order by createtime";
		$abc2 .= " and b.receivedtime='" . $fromdt . "' order by createtime";
		$abc3 .= " and b.receivedtime='" . $fromdt . "' order by createtime";
		$abc4 .= " and b.receivedtime='" . $fromdt . "' order by createtime";
	} else if ($todt != "") {
		$abc .= " and receivedtime='" . $todt . "' order by createtime";
		$abc2 .= " and receivedtime='" . $todt . "' order by createtime";
		$abc3 .= " and receivedtime='" . $todt . "' order by createtime";
		$abc4 .= " and receivedtime='" . $todt . "' order by createtime";
	} else {
		$fromdt = date('Y-m-d 00:00:00');
		$todt = date('Y-m-d 23:59:59');

		$abc .= " and b.receivedtime between '" . $fromdt . "' and '" . $todt . "' order by createtime";
		$abc2 .= " and b.receivedtime between '" . $fromdt . "' and '" . $todt . "' order by createtime";
		$abc3 .= " and b.receivedtime between '" . $fromdt . "' and '" . $todt . "' order by createtime";
		$abc4 .= " and b.receivedtime between '" . $fromdt . "' and '" . $todt . "' order by createtime";
	}


	?>

	<form action="export_Mains_UPS_Fail_securico.php" method="POST">

		<input type="hidden" name="exportsql" value="<?php echo $abc; ?>">
		<input type="hidden" name="exportsql2" value="<?php echo $abc2; ?>">
		<input type="hidden" name="exportsql3" value="<?php echo $abc3; ?>">
		<input type="hidden" name="exportsql4" value="<?php echo $abc4; ?>">
		<input type="submit" value="Export">

	</form>
	<?php

	// $abc .="limit 10 ";
// $abc2 .="limit 10 ";
// $abc3 .="limit 10 ";
// $abc4 .="limit 10 ";
	echo $abc . '<br />';
// 	echo $abc2 . '<br />';
// 	echo $abc3 . '<br />';
// 	echo $abc4	 . '<br />';



	// return ; 

	$result = mysqli_query($conn, $abc);
	$result2 = mysqli_query($conn, $abc2);
	$result3 = mysqli_query($conn, $abc3);
	$result4 = mysqli_query($conn, $abc4);

	$Num_Rows1 = mysqli_num_rows($result);
	$Num_Rows2 = mysqli_num_rows($result2);
	$Num_Rows3 = mysqli_num_rows($result3);
	$Num_Rows4 = mysqli_num_rows($result4);
	$Num_Rows = $Num_Rows1 + $Num_Rows2 + $Num_Rows3 + $Num_Rows4;

	?>

	<html>

	<style>
		table {
			width: 100%;
		}

		td {
			padding: 10px;
			font-size: 12px;
			font-weight: bold;
			color: #000;
		}

		tr:hover {
			background-color: #eee !important;
		}

		tr,
		th {
			padding: 10px;
			background-color: #8cb77e;
			color: #fff;
			font-size: 12px;
		}
	</style>

	<div align="center">total records:<?php echo $Num_Rows ?></div>
	<table border=1 style="margin-top:30px">
		<tr>
			<!--<th>sr</th>-->
			<th>Client</th>
			<th>Bank Name</th>
			<th>Incident Id</th>
			<th>Circle</th>
			<th>Location</th>

			<th>Address</th>
			<th>ATMID</th>
			<th>Full Address</th>
			<th>DVRIP</th>
			<th>Incident Date Time</th>
			<th>EB Power Failure Alert Received date</th>
			<th>EB Power Failure Alert Received Time</th>
			<th>UPS Power Available Alert Received Date</th>
			<th>UPS Power Available Alert Received time</th>

			<th>UPS Power Failure Alert Received Date</th>
			<th>UPS Power Failure Alert Received time</th>
			<th>UPS Power Restore Alert Received Date</th>
			<th>UPS Power Restore Alert Received time</th>
			<th>EB Power Available Alert Received date</th>
			<th>EB Power Available Alert Received time</th>


		</tr>

		<?php
		while ($row = mysqli_fetch_array($result)) { ?>

			<tr style="background-color:#cfe8c7">
				<!--<td><?php echo $sr; ?></td>-->
				<td><?php echo $row["Customer"]; ?></td>
				<td><?php echo $row["Bank"]; ?></td>
				<td><?php echo $row["id"]; ?></td>
				<td><?php echo $row["zon"]; ?></td>
				<td><?php echo $row["City"] . "," . $row["State"]; ?></td>
				<td><?php echo $row["ATMShortName"]; ?></td>



				<td><?php echo $row["ATMID"]; ?></td>
				<td><?php echo $row["SiteAddress"]; ?></td>
				<td><?php echo $row["DVRIP"]; ?></td>

				<?php
				//$dtconvt=$row["receivedtime"];
				//$timestamp = strtotime($dtconvt);
				//$newDate = date('d-F-Y', $timestamp); 
				//echo $newDate; //outputs 02-March-2011
				?>



				<td><?php echo $row["createtime"]; ?></td>
				<?php
				$timestamp = $row["createtime"];
				$splitTimeStamp = explode(" ", $timestamp);
				$date = $splitTimeStamp[0];
				$time = $splitTimeStamp[1];

				if ($row["alarm"] == "AT" and $row["zone"] == "029") {
					?>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>
					
					<?php
					$xyz = "select createtime from alerts_acup where panelid='" . $row['panelid'] . "' and zone='029' and alarm='AR' and createtime>'" . $row['createtime'] . "' order by createtime limit 1";
					$xyzresult = mysqli_query($conn, $xyz);
					if(mysqli_num_rows($xyzresult)>0){
						$xyzfetch = mysqli_fetch_array($xyzresult);
						$ac_restore_split = explode(" ",$xyzfetch[0]);
						$ac_restore_date = $ac_restore_split[0];
						$ac_restore_time = $ac_restore_split[1];
					}else{
						$ac_restore_date = "-";
						$ac_restore_time = "-";
					}
					
				} else {
					    $ac_restore_date = "-";
						$ac_restore_time = "-";
					?>
					<td>-</td>
					<td>-</td>
					<td>-</td>
					<td>-</td>

				<?php }
				if ($row["alarm"] == "AT" and $row["zone"] == "030") {
					?>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>
					<?php
					$xyz1 = "select createtime from alerts_acup where panelid='" . $row['panelid'] . "' and zone='030' and alarm='AR' and createtime>'" . $row['createtime'] . "' order by createtime limit 1";
					$xyzresult1 = mysqli_query($conn, $xyz1);
                    if(mysqli_num_rows($xyzresult1)>0){
						$xyzfetch1 = mysqli_fetch_array($xyzresult1);
						$ups_restore_split = explode(" ",$xyzfetch1[0]);
						$ups_restore_date = $ups_restore_split[0];
						$ups_restore_time = $ups_restore_split[1];
					}else{
                        $ups_restore_date = "-";
						$ups_restore_time = "-"; 
					}
					
				} else {
					    $ups_restore_date = "-";
						$ups_restore_time = "-"; 
					?>
					<td>-</td>
					<td>-</td>
				<?php }

				?>
				<td><?php echo $ups_restore_date; ?></td>
				<td><?php echo $ups_restore_time; ?></td>

				<td><?php echo $ac_restore_date; ?></td>
				<td><?php echo $ac_restore_time; ?></td>

			</tr>
			<?php
			$sr++;
			?>
			<?php
		}



		while ($row = mysqli_fetch_array($result2)) { ?>

			<tr style="background-color:#cfe8c7">
				<!--<td><?php echo $sr; ?></td>-->
				<td><?php echo $row["Customer"]; ?></td>
				<td><?php echo $row["Bank"]; ?></td>
				<td><?php echo $row["id"]; ?></td>
				<td><?php echo $row["zon"]; ?></td>
				<td><?php echo $row["City"] . "," . $row["State"]; ?></td>
				<td><?php echo $row["ATMShortName"]; ?></td>



				<td><?php echo $row["ATMID"]; ?></td>
				<td><?php echo $row["SiteAddress"]; ?></td>
				<td><?php echo $row["DVRIP"]; ?></td>

				<?php
				//$dtconvt=$row["receivedtime"];
				//$timestamp = strtotime($dtconvt);
				//$newDate = date('d-F-Y', $timestamp); 
				//echo $newDate; //outputs 02-March-2011
				?>



				<td><?php echo $row["createtime"]; ?></td>
				<?php
				$timestamp = $row["createtime"];
				$splitTimeStamp = explode(" ", $timestamp);
				$date = $splitTimeStamp[0];
				$time = $splitTimeStamp[1];

				if ($row["alarm"] == "BA" and $row["zone"] == "001") {
					?>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>

					<?php
					$xyz2 = "select createtime from alerts_acup where panelid='" . $row['panelid'] . "' and zone='001' and alarm='BR' and createtime>'" . $row['createtime'] . "' order by createtime limit 1";
					$xyzresult2 = mysqli_query($conn, $xyz2);
					
					if(mysqli_num_rows($xyzresult2)>0){
						$xyzfetch2 = mysqli_fetch_array($xyzresult2);
						$ac_restore_split1 = explode(" ",$xyzfetch2[0]);
						$ac_restore_date1 = $ac_restore_split1[0];
						$ac_restore_time1 = $ac_restore_split1[1];
					}else{
						$ac_restore_date1 = "-";
						$ac_restore_time1 = "-";
					}
				} else {
					//$xyzfetch2[0] = '-';
					$ac_restore_date1 = "-";
					$ac_restore_time1 = "-";
				?>
					<td>-</td>
					<td>-</td>
					<td>-</td>
					<td>-</td>

					<?php
				}
				if ($row["alarm"] == "BA" and $row["zone"] == "002") {
					?>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>
					<?php

					$xyz1 = "select createtime from alerts_acup where panelid='" . $row['panelid'] . "' and zone='002' and alarm='BR' and createtime>'" . $row['createtime'] . "' order by createtime limit 1";
					$xyzresult1 = mysqli_query($conn, $xyz1);
					if(mysqli_num_rows($xyzresult1)>0){
                        $xyzfetch3 = mysqli_fetch_array($xyzresult1);
						$ups_restore_split1 = explode(" ",$xyzfetch3[0]);
						$ups_restore_date1 = $ups_restore_split1[0];
						$ups_restore_time1 = $ups_restore_split1[1];
					}else{
                        $ups_restore_date1 = "-";
						$ups_restore_time1 = "-"; 
					}
					
				} else {
					//$xyzfetch3[0] = '-'; 
				    $ups_restore_date1 = "-";
					$ups_restore_time1 = "-"; 	
				?>
					<td>-</td>
					<td>-</td>
				<?php }

				?>
				<td><?php echo $ups_restore_date1; ?></td>
				<td><?php echo $ups_restore_time1; ?></td>

				<td><?php echo $ac_restore_date1; ?></td>
				<td><?php echo $ac_restore_time1; ?></td>

			</tr>
			<?php

			$sr++;
		}

		while ($row = mysqli_fetch_array($result3)) { ?>

			<tr style="background-color:#cfe8c7">
				<!--<td><?php echo $sr; ?></td>-->
				<td><?php echo $row["Customer"]; ?></td>
				<td><?php echo $row["Bank"]; ?></td>
				<td><?php echo $row["id"]; ?></td>
				<td><?php echo $row["zon"]; ?></td>
				<td><?php echo $row["City"] . "," . $row["State"]; ?></td>
				<td><?php echo $row["ATMShortName"]; ?></td>



				<td><?php echo $row["ATMID"]; ?></td>
				<td><?php echo $row["SiteAddress"]; ?></td>
				<td><?php echo $row["DVRIP"]; ?></td>

				<?php
				//$dtconvt=$row["receivedtime"];
				//$timestamp = strtotime($dtconvt);
				//$newDate = date('d-F-Y', $timestamp); 
				//echo $newDate; //outputs 02-March-2011
				?>



				<td><?php echo $row["createtime"]; ?></td>
				<?php
				$timestamp = $row["createtime"];
				$splitTimeStamp = explode(" ", $timestamp);
				$date = $splitTimeStamp[0];
				$time = $splitTimeStamp[1];

				if ($row["alarm"] == "BA" and $row["zone"] == "552") {
					?>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>

					<?php
					$xyz2 = "select createtime from alerts_acup where panelid='" . $row['panelid'] . "' and zone='552' and alarm='BR' and createtime>'" . $row['createtime'] . "' order by createtime limit 1";
					$xyzresult2 = mysqli_query($conn, $xyz2);
					//$xyzfetch2 = mysqli_fetch_array($xyzresult2);
					if(mysqli_num_rows($xyzresult2)>0){
						$xyzfetch2 = mysqli_fetch_array($xyzresult2);
						$ac_restore_split2 = explode(" ",$xyzfetch2[0]);
						$ac_restore_date2 = $ac_restore_split2[0];
						$ac_restore_time2 = $ac_restore_split2[1];
					}else{
						$ac_restore_date2 = "-";
						$ac_restore_time2 = "-";
					}
				} else {
					//$xyzfetch2[0] = '-';
					    $ac_restore_date2 = "-";
						$ac_restore_time2 = "-";
					?>
					<td>-</td>
					<td>-</td>
					<td>-</td>
					<td>-</td>

					<?php
				}
				if ($row["alarm"] == "BA" and $row["zone"] == "554") {
					?>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>
					<?php

					$xyz1 = "select createtime from alerts_acup where panelid='" . $row['panelid'] . "' and zone='554' and alarm='BR' and createtime>'" . $row['createtime'] . "' order by createtime limit 1";
					$xyzresult1 = mysqli_query($conn, $xyz1);
				//	$xyzfetch3 = mysqli_fetch_array($xyzresult1);

                    if(mysqli_num_rows($xyzresult1)>0){
                        $xyzfetch3 = mysqli_fetch_array($xyzresult1);
						$ups_restore_split2 = explode(" ",$xyzfetch3[0]);
						$ups_restore_date2 = $ups_restore_split2[0];
						$ups_restore_time2 = $ups_restore_split2[1];
					}else{
                        $ups_restore_date2 = "-";
						$ups_restore_time2 = "-"; 
					}

				} else {
					//$xyzfetch3[0] = '-'; 
					   $ups_restore_date2 = "-";
					   $ups_restore_time2 = "-"; 
					?>
					<td>-</td>
					<td>-</td>
				<?php 
				}
				?>
				<td><?php echo $ups_restore_date2; ?></td>
				<td><?php echo $ups_restore_time2; ?></td>

				<td><?php echo $ac_restore_date2; ?></td>
				<td><?php echo $ac_restore_time2; ?></td>

			</tr>
			<?php

			$sr++;
		}

		while ($row = mysqli_fetch_array($result4)) { ?>

			<tr style="background-color:#cfe8c7">
				<!--<td><?php echo $sr; ?></td>-->
				<td><?php echo $row["Customer"]; ?></td>
				<td><?php echo $row["Bank"]; ?></td>
				<td><?php echo $row["id"]; ?></td>
				<td><?php echo $row["zon"]; ?></td>
				<td><?php echo $row["City"] . "," . $row["State"]; ?></td>
				<td><?php echo $row["ATMShortName"]; ?></td>



				<td><?php echo $row["ATMID"]; ?></td>
				<td><?php echo $row["SiteAddress"]; ?></td>
				<td><?php echo $row["DVRIP"]; ?></td>

				<?php
				//$dtconvt=$row["receivedtime"];
				//$timestamp = strtotime($dtconvt);
				//$newDate = date('d-F-Y', $timestamp); 
				//echo $newDate; //outputs 02-March-2011
				?>



				<td><?php echo $row["createtime"]; ?></td>
				<?php
				$timestamp = $row["createtime"];
				$splitTimeStamp = explode(" ", $timestamp);
				$date = $splitTimeStamp[0];
				$time = $splitTimeStamp[1];

				if ($row["alarm"] == "BA" and $row["zone"] == "027") {
					?>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>

					<?php
					$xyz2 = "select createtime from alerts_acup where panelid='" . $row['panelid'] . "' and zone='027' and alarm='BR' and createtime>'" . $row['createtime'] . "' order by createtime limit 1";
					$xyzresult2 = mysqli_query($conn, $xyz2);
				//	$xyzfetch2 = mysqli_fetch_array($xyzresult2);
					if(mysqli_num_rows($xyzresult2)>0){
						$xyzfetch2 = mysqli_fetch_array($xyzresult2);
						$ac_restore_split3 = explode(" ",$xyzfetch2[0]);
						$ac_restore_date3 = $ac_restore_split3[0];
						$ac_restore_time3 = $ac_restore_split3[1];
					}else{
						$ac_restore_date3 = "-";
						$ac_restore_time3 = "-";
					}
				} else {
					//$xyzfetch2[0] = '-';
					    $ac_restore_date3 = "-";
						$ac_restore_time3 = "-";
					?>
					<td>-</td>
					<td>-</td>
					<td>-</td>
					<td>-</td>

					<?php
				}
				if ($row["alarm"] == "BA" and $row["zone"] == "028") {
					?>
					<td><?php echo $date; ?></td>
					<td><?php echo $time; ?></td>
					<?php

					$xyz1 = "select createtime from alerts_acup where panelid='" . $row['panelid'] . "' and zone='028' and alarm='BR' and createtime>'" . $row['createtime'] . "' order by createtime limit 1";
					$xyzresult1 = mysqli_query($conn, $xyz1);
				//	$xyzfetch3 = mysqli_fetch_array($xyzresult1);
					if(mysqli_num_rows($xyzresult1)>0){
                        $xyzfetch3 = mysqli_fetch_array($xyzresult1);
						$ups_restore_split3 = explode(" ",$xyzfetch3[0]);
						$ups_restore_date3 = $ups_restore_split3[0];
						$ups_restore_time3 = $ups_restore_split3[1];
					}else{
                        $ups_restore_date3 = "-";
						$ups_restore_time3 = "-"; 
					}
				} else {
					//$xyzfetch3[0] = '-'; 
					    $ups_restore_date3 = "-";
						$ups_restore_time3 = "-"; 
				?>
					<td>-</td>
					<td>-</td>
				<?php }

				?>
				<td><?php echo $ups_restore_date3; ?></td>
				<td><?php echo $ups_restore_time3; ?></td>

				<td><?php echo $ac_restore_date3; ?></td>
				<td><?php echo $ac_restore_time3; ?></td>

			</tr>
			<?php

			$sr++;
		}

		?>

	</table>

	</form>

	<?php
	/*
	  if($Prev_Page) 
	  {
		  echo " <center><a href=\"JavaScript:a('$Prev_Page','perpg')\"> << Back></center></a> ";
	  }

	  if($Page!=$Num_Pages)
	  {
		  echo " <center><a href=\"JavaScript:a('$Next_Page','perpg')\">Next >></center></a> ";
	  }
	  */
	?>

	</div>

	</body>

	</html>


	<?php
} else {
	header("location: index.php");
}
?>