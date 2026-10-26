<?php
require_once __DIR__.'/Codec.php';
class ViessmannOpenV extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('Interval', 10);
        $this->RegisterPropertyInteger('Timeout', 10);
        $this->RegisterPropertyString('VarList', file_get_contents(__DIR__.'/registers.json'));
        $this->RegisterTimer('Poll', 0, 'VIO_SyncData($_IPS["TARGET"]);');
        $this->RegisterTimer('Watchdog', 0, 'VIO_CheckTimeout($_IPS["TARGET"]);');
        $this->ConnectParent('{6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}');
    }
    private function registers(): array
    {
        $rows = json_decode($this->ReadPropertyString('VarList'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows)) throw new InvalidArgumentException('Registerliste erforderlich');
        $names = [];
        foreach ($rows as $r) {
            OpenVCodec::validate($r);
            if (isset($names[$r['Name']])) throw new InvalidArgumentException('Doppelter Ident');
            $names[$r['Name']] = true;
        }
        return $rows;
    }
    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('Poll', 0);
        $this->SetTimerInterval('Watchdog', 0);
        $this->SetBuffer('Queue', '[]');
        $this->SetBuffer('Active', '');
        try {
            if ($this->ReadPropertyInteger('Interval') < 1 || $this->ReadPropertyInteger('Timeout') < 1) throw new InvalidArgumentException('Interval und Timeout müssen positiv sein');
            $this->registerProfiles();
            foreach ($this->registers() as $r) {
                $method = ['bool'=>'RegisterVariableBoolean','int'=>'RegisterVariableInteger','float'=>'RegisterVariableFloat','string'=>'RegisterVariableString'][$r['Typ']];
                $this->$method($r['Name'], $r['Name'], $this->profileFor($r));
                if ($r['only_read']) $this->DisableAction($r['Name']); else $this->EnableAction($r['Name']);
            }
            $this->SetTimerInterval('Poll', 1000*$this->ReadPropertyInteger('Interval'));
            $this->SetTimerInterval('Watchdog', 1000);
            $this->SetStatus(104);
        } catch (Throwable $e) { $this->fail($e->getMessage()); }
    }
    private function registerProfiles(): void
    {
        foreach (['VIO.Temperature'=>[2, ' °C', 1], 'VIO.RawFloat'=>[2, '', 0], 'VIO.RawInteger'=>[1, '', 0]] as $name=>$definition) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $definition[0]);
                IPS_SetVariableProfileText($name, '', $definition[1]);
                IPS_SetVariableProfileDigits($name, $definition[2]);
                if ($name === 'VIO.Temperature') IPS_SetVariableProfileIcon($name, 'Temperature');
            }
        }
        if (!IPS_VariableProfileExists('VIO.State')) {
            IPS_CreateVariableProfile('VIO.State', 0);
            IPS_SetVariableProfileAssociation('VIO.State', 0, '0', '', -1);
            IPS_SetVariableProfileAssociation('VIO.State', 1, '1', '', -1);
        }
    }
    private function profileFor(array $r): string
    {
        $profile = trim((string)($r['Profile'] ?? ''));
        if ($profile === '') {
            $temperatures = ['Ablufttemperatur','Fortlufttemperatur','luftungaussentemperatur',
                'raumsolltemp','RuecklauftemperaturSekundaer','VorlauftemperaturPrimaerquelle',
                'VorlauftemperaturSekundaer','Warmwasser','warmwassersolltemp','Zulufttemperatur'];
            if ($r['Typ'] === 'float' && in_array($r['Name'], $temperatures, true)) $profile = 'VIO.Temperature';
            elseif ($r['Typ'] === 'bool') $profile = $r['Name'] === 'VentilHeizenWarmwasser' ? 'VIO.State' : '~Switch';
            elseif ($r['Typ'] === 'float') $profile = 'VIO.RawFloat';
            elseif ($r['Typ'] === 'int') $profile = 'VIO.RawInteger';
        }
        if ($profile !== '') {
            $type = ['bool'=>0,'int'=>1,'float'=>2,'string'=>3][$r['Typ']];
            if (!IPS_VariableProfileExists($profile) || IPS_GetVariableProfile($profile)['ProfileType'] !== $type) {
                throw new InvalidArgumentException('Profil fehlt oder hat falschen Typ: '.$profile);
            }
        }
        return $profile;
    }
    private function locked(callable $f): void
    {
        if (!IPS_SemaphoreEnter('VIO_'.$this->InstanceID, 5000)) throw new RuntimeException('Kommunikation belegt');
        try { $f(); } finally { IPS_SemaphoreLeave('VIO_'.$this->InstanceID); }
    }
    private function queue(): array { return json_decode($this->GetBuffer('Queue') ?: '[]', true); }
    private function fail(string $message): void
    {
        $this->SetStatus(201);
        $this->SendDebug('Fehler', $message, 0);
    }
    public function SyncData()
    {
        $this->locked(function () {
            try {
                if ($this->GetBuffer('Active') !== '' || count($this->queue()) > 0) return;
                $q = [];
                foreach ($this->registers() as $r) $q[] = ['r'=>$r, 'write'=>false, 'payload'=>''];
                $this->SetBuffer('Queue', json_encode($q));
            } catch (Throwable $e) { $this->fail($e->getMessage()); }
        });
    }
    public function RequestAction($Ident, $Value)
    {
        $this->locked(function () use ($Ident, $Value) {
            foreach ($this->registers() as $r) {
                if ($r['Name'] !== $Ident) continue;
                if ($r['only_read']) throw new InvalidArgumentException('Register ist nur lesbar');
                $payload = OpenVCodec::encode($r, $Value);
                $q = $this->queue();
                if (count($q) >= 100) throw new RuntimeException('Warteschlange voll');
                $q[] = ['r'=>$r,'write'=>true,'payload'=>bin2hex($payload)];
                $this->SetBuffer('Queue', json_encode($q));
                return;
            }
            throw new InvalidArgumentException('Unbekannter Ident');
        });
    }
    private function send(string $data): void
    {
        // Symcon transport represents each byte as a Unicode code point (Latin-1).
        $encoded = '';
        foreach (unpack('C*', $data) as $b) $encoded .= $b < 128 ? chr($b) : chr(192|($b>>6)).chr(128|($b&63));
        $this->SendDataToParent(json_encode(['DataID'=>'{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Buffer'=>$encoded]));
    }
    public function ReceiveData($JSONString)
    {
        $this->locked(function () use ($JSONString) {
            try {
                $message = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
                $data = iconv('UTF-8','ISO-8859-1', $message['Buffer']);
                if ($data === false) throw new RuntimeException('Ungültiger Empfang');
                $active = $this->GetBuffer('Active');
                if ($active === '') {
                    // Start a fresh transaction only on the standalone KW ready byte.
                    if ($data !== "\x05") return;
                    $q = $this->queue();
                    if (!$q) return;
                    $a = array_shift($q);
                    $a['received'] = '';
                    $a['since'] = microtime(true);
                    $this->SetBuffer('Queue', json_encode($q));
                    $this->SetBuffer('Active', json_encode($a));
                    $this->send("\x01".OpenVCodec::frame($a['r'], $a['write'] ? hex2bin($a['payload']) : null));
                    return;
                }
                $a = json_decode($active, true);
                $data = hex2bin($a['received']).$data;
                $expected = $a['write'] ? 1 : $a['r']['length'];
                if (strlen($data) < $expected) {
                    $a['received'] = bin2hex($data);
                    $this->SetBuffer('Active', json_encode($a));
                    return;
                }
                $this->SetBuffer('Active', '');
                if (strlen($data) !== $expected) throw new RuntimeException('Unerwartete Antwortlänge; Transaktion verworfen');
                if ($a['write']) {
                    if ($data !== "\0") throw new RuntimeException('Schreiben von der Regelung nicht bestätigt');
                    // Read back rather than publishing the requested value optimistically.
                    $q = $this->queue();
                    array_unshift($q, ['r'=>$a['r'],'write'=>false,'payload'=>'']);
                    $this->SetBuffer('Queue', json_encode($q));
                } else {
                    $this->SetValue($a['r']['Name'], OpenVCodec::decode($a['r'], $data));
                }
                $this->SetStatus(102);
                // End session; next request waits for a fresh ready signal.
                $this->send("\x04");
            } catch (Throwable $e) {
                $this->SetBuffer('Active', '');
                $this->SetBuffer('Queue', '[]');
                $this->fail($e->getMessage());
            }
        });
    }
    public function CheckTimeout()
    {
        $this->locked(function () {
            $a = json_decode($this->GetBuffer('Active'), true);
            if (!$a || microtime(true)-$a['since'] < $this->ReadPropertyInteger('Timeout')) return;
            $this->SetBuffer('Active', '');
            $this->SetBuffer('Queue', '[]');
            $this->fail('Antworttimeout; Schreibauftrag wird nicht automatisch wiederholt');
            $this->send("\x04");
        });
    }
    public function GetConfigurationForParent()
    {
        return json_encode(['BaudRate'=>4800,'DataBits'=>8,'StopBits'=>2,'Parity'=>'Even']);
    }
}
