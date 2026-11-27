<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileHelper.php';

/**
 * HTML-SDK-Kachel: Haustier-Tracker (z. B. Tractive) - eine Instanz pro Tier.
 *
 * Zeigt Status, Akkustand als Balken, Geschwindigkeit und Positionsgenauigkeit,
 * letzte Positionsmeldung, Sensor und Schlafzeiten. Die Tracker-Funktionen
 * Signalton, Signallicht und Live-Tracking sind als Schalter bedienbar (nur
 * Bool-Variablen mit Aktion). Jede Variable ist optional; Klick auf einen Wert
 * öffnet die Variable.
 */
class HundKachel extends IPSModuleStrict
{
    use TileHelper;

    private const VARIABLE_PROPERTIES = ['StatusID', 'BatteryID', 'AwayID', 'SpeedID', 'AccuracyID', 'SensorID', 'AltitudeID', 'LastPosID', 'DaySleepID', 'NightSleepID', 'RestID', 'SoundID', 'LightID', 'LiveID', 'WalksID'];
    private const SWITCH_PROPERTIES = ['SoundID', 'LightID', 'LiveID'];
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const WALK_CACHE_SECONDS = 600;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Name', '');
        $this->RegisterPropertyString('Icon', 'dog');
        foreach (self::VARIABLE_PROPERTIES as $property) {
            $this->RegisterPropertyInteger($property, 0);
        }
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML') ? constant('INSTANCE_VISUALIZATION_TYPE_HTML') : 1);
    }

    public function Destroy(): void
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML') ? constant('INSTANCE_VISUALIZATION_TYPE_HTML') : 1);

        // Nachrichten und Referenzen passend zur Konfiguration neu aufbauen.
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }
        $count = 0;
        foreach (self::VARIABLE_PROPERTIES as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && IPS_VariableExists($id)) {
                $count++;
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        $this->SetStatus($count > 0 ? IS_ACTIVE : IS_INACTIVE);
        $this->TilePush();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            // Eine neue Gassi-Runde macht den Cache ungültig.
            if ($SenderID === $this->ReadPropertyInteger('WalksID')) {
                $this->SetBuffer('WalkCache', '');
            }
            $this->TilePush();
        }
    }

    /** Tipp auf einen Schalter: nur die konfigurierten Bool-Variablen mit Aktion. */
    public function RequestAction(string $ident, mixed $value): void
    {
        if ($ident !== 'Press') {
            throw new Exception('Invalid ident: ' . $ident);
        }
        $objectID = (int) $value;
        $allowed = false;
        foreach (self::SWITCH_PROPERTIES as $property) {
            if ($this->ReadPropertyInteger($property) === $objectID) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new Exception('Object not configured: ' . $objectID);
        }
        if (IPS_VariableExists($objectID) && IPS_GetVariable($objectID)['VariableType'] === VARIABLETYPE_BOOLEAN && HasAction($objectID)) {
            RequestAction($objectID, !GetValueBoolean($objectID));
            $this->TilePush();
        }
    }

    private function TileFile(): string
    {
        return __DIR__ . '/tile.html';
    }

    private function TileState(): array
    {
        $batteryRaw = $this->TileRaw($this->ReadPropertyInteger('BatteryID'));
        $awayRaw = $this->TileRaw($this->ReadPropertyInteger('AwayID'));

        $name = $this->ReadPropertyString('Name');
        return [
            'name'      => $name !== '' ? $name : IPS_GetName($this->InstanceID),
            'icon'      => $this->ReadPropertyString('Icon') !== '' ? $this->ReadPropertyString('Icon') : 'dog',
            'status'    => $this->TileFormatted($this->ReadPropertyInteger('StatusID')),
            'battery'   => is_numeric($batteryRaw) ? (float) $batteryRaw : null,
            'batteryText' => $this->TileFormatted($this->ReadPropertyInteger('BatteryID')),
            'away'      => is_bool($awayRaw) ? $awayRaw : null,
            'awayText'  => $this->TileFormatted($this->ReadPropertyInteger('AwayID')),
            'speed'     => $this->TileFormatted($this->ReadPropertyInteger('SpeedID')),
            'accuracy'  => $this->TileFormatted($this->ReadPropertyInteger('AccuracyID')),
            'sensor'    => $this->TileFormatted($this->ReadPropertyInteger('SensorID')),
            'altitude'  => $this->TileFormatted($this->ReadPropertyInteger('AltitudeID')),
            'lastPos'   => $this->TileFormatted($this->ReadPropertyInteger('LastPosID')),
            'daySleep'  => $this->TileFormatted($this->ReadPropertyInteger('DaySleepID')),
            'nightSleep'=> $this->TileFormatted($this->ReadPropertyInteger('NightSleepID')),
            'rest'      => $this->TileFormatted($this->ReadPropertyInteger('RestID')),
            'switches'  => $this->Switches(),
            'walks'     => $this->Walks(),
            'open'      => $this->OpenIDs()
        ];
    }

    /**
     * Gassi-Runden der letzten 7 Tage aus dem Archiv der (geloggten) String-Variable,
     * z. B. "27.11.2026 07:21-08:08 | 5,02 km | 48 min": die letzten Runden als Liste
     * plus die Gesamt-Kilometer pro Tag für den Wochen-Graphen.
     */
    private function Walks(): ?array
    {
        $variableID = $this->ReadPropertyInteger('WalksID');
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            return null;
        }
        $cache = json_decode($this->GetBuffer('WalkCache'), true);
        if (is_array($cache) && ($cache['time'] ?? 0) > time() - self::WALK_CACHE_SECONDS && ($cache['day'] ?? '') === date('Y-m-d')) {
            return $cache['data'];
        }

        $archiveIDs = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        if (count($archiveIDs) === 0) {
            return null;
        }
        $records = @AC_GetLoggedValues($archiveIDs[0], $variableID, strtotime('-6 days 00:00'), time(), 0);
        if (!is_array($records)) {
            return null;
        }
        $walks = [];
        $perDay = [];
        // Neueste zuerst; unparsebare Einträge werden unverändert angezeigt.
        foreach ($records as $record) {
            $value = trim((string) $record['Value']);
            if ($value === '') {
                continue;
            }
            $day = date('Y-m-d', (int) $record['TimeStamp']);
            $walk = ['when' => date('d.m. H:i', (int) $record['TimeStamp']), 'text' => $value];
            if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}:\d{2})-(\d{2}:\d{2})\s*\|\s*([\d.,]+)\s*km\s*\|\s*(\d+)\s*min/u', $value, $m)) {
                $km = (float) str_replace(',', '.', $m[6]);
                $day = "{$m[3]}-{$m[2]}-{$m[1]}";
                $walk = ['when' => "{$m[1]}.{$m[2]}. {$m[4]}", 'text' => sprintf('%s km · %d min', number_format($km, 2, ',', ''), (int) $m[7]), 'km' => $km];
                $perDay[$day] = round(($perDay[$day] ?? 0.0) + $km, 2);
            }
            if (count($walks) < 3) {
                $walks[] = $walk;
            }
        }
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $timestamp = strtotime("-{$i} days 00:00");
            $days[] = ['t' => $timestamp, 'v' => $perDay[date('Y-m-d', $timestamp)] ?? 0.0];
        }
        $data = ['list' => $walks, 'days' => $days];
        $this->SetBuffer('WalkCache', (string) json_encode(['time' => time(), 'day' => date('Y-m-d'), 'data' => $data]));
        return $data;
    }

    /** Schalter-Zustände: nur Bool-Variablen mit Aktion sind bedienbar. */
    private function Switches(): array
    {
        $labels = ['SoundID' => 'Sound', 'LightID' => 'Light', 'LiveID' => 'Live'];
        $icons = ['SoundID' => 'volume-high', 'LightID' => 'lightbulb', 'LiveID' => 'location-crosshairs'];
        $switches = [];
        foreach (self::SWITCH_PROPERTIES as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id <= 0 || !IPS_VariableExists($id) || IPS_GetVariable($id)['VariableType'] !== VARIABLETYPE_BOOLEAN) {
                continue;
            }
            $switches[] = [
                'id'     => $id,
                'label'  => $labels[$property],
                'icon'   => $icons[$property],
                'on'     => GetValueBoolean($id),
                'action' => HasAction($id)
            ];
        }
        return $switches;
    }

    /** Variablen-IDs für das Öffnen per Klick (nur konfigurierte). */
    private function OpenIDs(): array
    {
        $open = [];
        foreach (self::VARIABLE_PROPERTIES as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && IPS_VariableExists($id)) {
                $open[lcfirst(substr($property, 0, -2))] = $id;
            }
        }
        return $open;
    }
}
