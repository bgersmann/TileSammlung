<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileHelper.php';

/**
 * HTML-SDK-Kachel: Wetterübersicht.
 *
 * Zeigt Temperatur, gefühlte Temperatur, Luftfeuchte, Taupunkt, Luftdruck, UV-Index,
 * Wind (Richtung + Geschwindigkeit, optional Böe) und den Regen von heute mit einem
 * kleinen Stundengraphen aus dem Archiv. Jede Variable ist optional - nicht
 * konfigurierte Werte werden nicht angezeigt.
 */
class WetterKachel extends IPSModuleStrict
{
    use TileHelper;

    private const VARIABLE_PROPERTIES = ['TemperatureID', 'FeelsID', 'HumidityID', 'DewpointID', 'PressureID', 'UVID', 'WindSpeedID', 'WindDirID', 'WindGustID', 'RainTodayID', 'RainGraphID'];
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const RAIN_CACHE_SECONDS = 300;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Name', '');
        $this->RegisterPropertyString('Icon', 'cloud-sun');
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

        $this->SetBuffer('RainCache', '');
        $this->SetStatus($count > 0 ? IS_ACTIVE : IS_INACTIVE);
        $this->TilePush();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->TilePush();
        }
    }

    private function TileFile(): string
    {
        return __DIR__ . '/tile.html';
    }

    private function TileState(): array
    {
        $uvRaw = $this->TileRaw($this->ReadPropertyInteger('UVID'));
        $windDirRaw = $this->TileRaw($this->ReadPropertyInteger('WindDirID'));

        $name = $this->ReadPropertyString('Name');
        return [
            'name'        => $name !== '' ? $name : IPS_GetName($this->InstanceID),
            'icon'        => $this->ReadPropertyString('Icon') !== '' ? $this->ReadPropertyString('Icon') : 'cloud-sun',
            'temperature' => $this->TileFormatted($this->ReadPropertyInteger('TemperatureID')),
            'feels'       => $this->TileFormatted($this->ReadPropertyInteger('FeelsID')),
            'humidity'    => $this->TileFormatted($this->ReadPropertyInteger('HumidityID')),
            'dewpoint'    => $this->TileFormatted($this->ReadPropertyInteger('DewpointID')),
            'pressure'    => $this->TileFormatted($this->ReadPropertyInteger('PressureID')),
            'uv'          => is_numeric($uvRaw) ? (float) $uvRaw : null,
            'windSpeed'   => $this->TileFormatted($this->ReadPropertyInteger('WindSpeedID')),
            'windGust'    => $this->TileFormatted($this->ReadPropertyInteger('WindGustID')),
            'windDir'     => is_numeric($windDirRaw) ? (float) $windDirRaw : null,
            'rainToday'   => $this->TileFormatted($this->ReadPropertyInteger('RainTodayID')),
            'rain'        => $this->RainGraph(),
            'open'        => [
                'temperature' => $this->OpenID('TemperatureID'),
                'feels'       => $this->OpenID('FeelsID'),
                'humidity'    => $this->OpenID('HumidityID'),
                'dewpoint'    => $this->OpenID('DewpointID'),
                'pressure'    => $this->OpenID('PressureID'),
                'uv'          => $this->OpenID('UVID'),
                'wind'        => $this->OpenID('WindSpeedID'),
                'rain'        => $this->OpenID('RainTodayID') ?: $this->OpenID('RainGraphID')
            ]
        ];
    }

    /** Variablen-ID für das Öffnen per Klick, 0 wenn nicht konfiguriert. */
    private function OpenID(string $property): int
    {
        $id = $this->ReadPropertyInteger($property);
        return ($id > 0 && IPS_VariableExists($id)) ? $id : 0;
    }

    /**
     * Stundenwerte des Regens von heute aus dem Archiv (gecacht, damit nicht jeder
     * Push neu aggregiert). Für eine kumulierte Tagesmenge ergibt Max-Min je Stunde
     * den Stundenregen; bei Zähler-Aggregation liefert das Archiv die Menge direkt.
     */
    private function RainGraph(): ?array
    {
        $variableID = $this->ReadPropertyInteger('RainGraphID');
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            return null;
        }
        $cache = json_decode($this->GetBuffer('RainCache'), true);
        if (is_array($cache) && ($cache['time'] ?? 0) > time() - self::RAIN_CACHE_SECONDS && ($cache['day'] ?? '') === date('Y-m-d')) {
            return $cache['data'];
        }

        $archiveIDs = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        if (count($archiveIDs) === 0) {
            return null;
        }
        $archiveID = $archiveIDs[0];
        $start = strtotime('today');
        $isCounter = @AC_GetAggregationType($archiveID, $variableID) === 1;
        $records = @AC_GetAggregatedValues($archiveID, $variableID, 0, $start, time(), 0);
        if (!is_array($records)) {
            return null;
        }
        $values = array_fill(0, 24, null);
        foreach ($records as $record) {
            $hour = (int) date('G', (int) $record['TimeStamp']);
            if ($hour >= 0 && $hour < 24) {
                $values[$hour] = round($isCounter ? max(0.0, (float) $record['Avg']) : max(0.0, (float) $record['Max'] - (float) $record['Min']), 2);
            }
        }
        $data = ['values' => $values, 'hour' => (int) date('G')];
        $this->SetBuffer('RainCache', (string) json_encode(['time' => time(), 'day' => date('Y-m-d'), 'data' => $data]));
        return $data;
    }
}
