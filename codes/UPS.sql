RASS :
================
insert into alerts_acup (id,panelid,seqno,zone,alarm,createtime,receivedtime,comment,status,sendtoclient,closedBy,closedtime,sendip,alerttype,location,priority,AlertUserStatus)
SELECT b.id,b.panelid,b.seqno,b.zone,b.alarm,b.createtime,b.receivedtime,b.comment,b.status,b.sendtoclient,b.closedBy,b.closedtime,b.sendip,b.alerttype,b.location,b.priority,b.AlertUserStatus  FROM `sites` a,`backalerts_backup` b WHERE (a.OldPanelID=b.panelid or a.NewPanelID=b.panelid) AND (a.Panel_Make='RASS' OR a.Panel_Make = 'rass_boi' OR a.Panel_Make = 'rass_pnb' OR a.Panel_Make='rass_sbi') AND (b.alarm IN ('AT','AR') AND b.zone IN ('029','030')) AND CAST(b.receivedtime AS DATE)= '2023-02-01'

Securico : 
=================
insert into alerts_acup (id,panelid,seqno,zone,alarm,createtime,receivedtime,comment,status,sendtoclient,closedBy,closedtime,sendip,alerttype,location,priority,AlertUserStatus)
SELECT b.id,b.panelid,b.seqno,b.zone,b.alarm,b.createtime,b.receivedtime,b.comment,b.status,b.sendtoclient,b.closedBy,b.closedtime,b.sendip,b.alerttype,b.location,b.priority,b.AlertUserStatus FROM `sites` a,`backalerts_backup` b WHERE (a.OldPanelID=b.panelid or a.NewPanelID=b.panelid) AND (a.Panel_Make = 'securico_gx4816' OR a.Panel_Make = 'sec_sbi') AND (b.alarm IN ('BA','BR') AND b.zone IN ('551','552')) AND CAST(b.receivedtime AS DATE)= '2023-03-01'

insert into alerts_acup (id,panelid,seqno,zone,alarm,createtime,receivedtime,comment,status,sendtoclient,closedBy,closedtime,sendip,alerttype,location,priority,AlertUserStatus)
SELECT b.id,b.panelid,b.seqno,b.zone,b.alarm,b.createtime,b.receivedtime,b.comment,b.status,b.sendtoclient,b.closedBy,b.closedtime,b.sendip,b.alerttype,b.location,b.priority,b.AlertUserStatus FROM `sites` a,`backalerts_backup` b WHERE (a.OldPanelID=b.panelid or a.NewPanelID=b.panelid) AND a.Panel_Make = 'SEC' AND (b.alarm IN ('BA','BR') AND b.zone IN ('027','028')) AND CAST(b.receivedtime AS DATE)= '2022-03-28'
 
Smart -I :
=================
insert into alerts_acup (id,panelid,seqno,zone,alarm,createtime,receivedtime,comment,status,sendtoclient,closedBy,closedtime,sendip,alerttype,location,priority,AlertUserStatus)
SELECT b.id,b.panelid,b.seqno,b.zone,b.alarm,b.createtime,b.receivedtime,b.comment,b.status,b.sendtoclient,b.closedBy,b.closedtime,b.sendip,b.alerttype,b.location,b.priority,b.AlertUserStatus FROM `backalerts_backup` b,sites a WHERE (a.OldPanelID=b.panelid or a.NewPanelID=b.panelid) AND (a.Panel_Make='SMART -I' OR a.Panel_Make ='SMART -IN' OR a.Panel_Make ='smarti_boi' OR a.Panel_Make ='smarti_pnb') AND (b.alarm IN ('BA','BR') AND b.zone IN ('001','002')) AND CAST(b.receivedtime AS DATE)= '2023-03-01' 

