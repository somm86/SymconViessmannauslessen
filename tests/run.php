<?php
error_reporting(E_ALL);
set_error_handler(function ($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
function IPS_SemaphoreEnter($name,$timeout) { return true; }
function IPS_SemaphoreLeave($name) {}
class IPSModule {
    public $InstanceID = 1;
    public $props = [], $buffers = [], $values = [], $sent = [], $actions = [], $status;
    function Create() {} function ApplyChanges() {}
    function RegisterPropertyInteger($k,$v) { $this->props[$k]=$v; }
    function RegisterPropertyString($k,$v) { $this->props[$k]=$v; }
    function ReadPropertyInteger($k) { return $this->props[$k]; }
    function ReadPropertyString($k) { return $this->props[$k]; }
    function RegisterTimer(...$a) {} function ConnectParent(...$a) {} function SetTimerInterval(...$a) {}
    function SetBuffer($k,$v) { $this->buffers[$k]=$v; }
    function GetBuffer($k) { return $this->buffers[$k] ?? ''; }
    function SetStatus($v) { $this->status=$v; }
    function SendDebug(...$a) {}
    function RegisterVariableBoolean(...$a) {} function RegisterVariableInteger(...$a) {}
    function RegisterVariableFloat(...$a) {} function RegisterVariableString(...$a) {}
    function EnableAction($k) { $this->actions[$k]=true; }
    function DisableAction($k) { $this->actions[$k]=false; }
    function SetValue($k,$v) { $this->values[$k]=$v; }
    function SendDataToParent($json) { $this->sent[]=iconv('UTF-8','ISO-8859-1',json_decode($json,true)['Buffer']); }
}
require __DIR__.'/../ViessmannOpenV/module.php';
$count=0;
function eq($a,$b) { global $count; $count++; if ($a !== $b) throw new RuntimeException('Expected '.var_export($b,true).' got '.var_export($a,true)); }
function rejects(callable $f) { global $count; $count++; try { $f(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Expected rejection'); }
function receive($m,$data) { $m->ReceiveData(json_encode(['Buffer'=>iconv('ISO-8859-1','UTF-8',$data)])); }
$rows=json_decode(file_get_contents(__DIR__.'/../ViessmannOpenV/registers.json'),true);
foreach ($rows as $r) OpenVCodec::validate($r);
eq(count($rows),18);
$r=$rows[8];
eq(bin2hex(OpenVCodec::encode($r,21.5)), 'd700');
eq(bin2hex(OpenVCodec::frame($r,OpenVCodec::encode($r,21.5))), 'f4200002d700');
eq(OpenVCodec::decode($r,hex2bin('d700')),21.5);
rejects(fn()=>OpenVCodec::encode($r,21.55));
rejects(fn()=>OpenVCodec::encode($r,-1));
rejects(fn()=>OpenVCodec::encode($r,7000));
$r['signed']=true;
eq(bin2hex(OpenVCodec::encode($r,-5.5)),'c9ff');
eq(OpenVCodec::decode($r,hex2bin('c9ff')),-5.5);
$r=$rows[5];
eq(bin2hex(OpenVCodec::encode($r,false)),'00');
eq(bin2hex(OpenVCodec::encode($r,true)),'01');
$r=$rows[1];
eq(bin2hex(OpenVCodec::encode($r,'0200')),'0200');
rejects(fn()=>OpenVCodec::encode($r,'02'));
$r=$rows[0]; $r['signed']=true;
eq(OpenVCodec::decode($r,hex2bin('c9ffff')),-5.5);
$m=new ViessmannOpenV(); $m->Create(); $m->ApplyChanges();
eq($m->status,104);
eq($m->actions['raumsolltemp'],true);
eq($m->actions['Ablufttemperatur'],false);
rejects(fn()=>$m->RequestAction('Ablufttemperatur',20));
rejects(fn()=>$m->RequestAction('NichtVorhanden',20));
$m->RequestAction('raumsolltemp',21.5);
eq(count($m->sent),0);
receive($m,"\x05");
eq(bin2hex($m->sent[0]),'01f4200002d700');
eq($m->values,[]);
receive($m,"\x00");
eq(bin2hex($m->sent[1]),'04');
eq($m->values,[]);
receive($m,"\x05");
eq(bin2hex($m->sent[2]),'01f7200002');
receive($m,"\xd2"); eq($m->values,[]);
receive($m,"\x00"); eq($m->values['raumsolltemp'],21.0);
$m->RequestAction('warmwassersolltemp',50);
receive($m,"\x05"); receive($m,"\x01");
eq($m->status,201); eq($m->GetBuffer('Queue'),'[]');
$m->RequestAction('raumsolltemp',22);
receive($m,"\x05");
$a=json_decode($m->GetBuffer('Active'),true); $a['since']-=20; $m->SetBuffer('Active',json_encode($a));
$m->CheckTimeout(); eq($m->status,201); eq($m->GetBuffer('Active'),''); eq($m->GetBuffer('Queue'),'[]');
$m->SyncData(); $m->SyncData(); eq(count(json_decode($m->GetBuffer('Queue'),true)),18);
receive($m,"\x05"); receive($m,"\x01\x02\x03\x04"); eq($m->status,201);
echo "$count checks passed\n";
