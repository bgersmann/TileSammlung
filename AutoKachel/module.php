<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileHelper.php';

/**
 * HTML-SDK-Kachel: Fahrzeugübersicht - eine Instanz pro Auto.
 *
 * Zeigt Füllstand (Tank oder Akku) als Balken, Reichweite und Kilometerstand als
 * Kennzahlen, Verriegelung, Türen/Fenster/Kofferraum sowie Parkadresse und letzte
 * Aktualisierung. Jede Variable ist optional, dadurch passt die Kachel für Autos
 * mit vielen und mit wenigen Datenpunkten. Klick auf einen Wert öffnet die Variable.
 */
class AutoKachel extends IPSModuleStrict
{
    use TileHelper;

    private const VARIABLE_PROPERTIES = ['LevelID', 'RangeID', 'MileageID', 'LockID', 'ParkID', 'DoorsID', 'WindowsID', 'TrunkID', 'AddressID', 'UpdatedID', 'ChargeModeID', 'ChargeTargetID', 'PhaseModeID', 'PhasesActiveID', 'ChargeActiveID', 'TargetSocID', 'TargetDateUseID', 'TargetDateID', 'FullPowerID'];
    private const TOGGLE_PROPERTIES = ['ChargeActiveID', 'TargetDateUseID'];
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const WEEK_CACHE_SECONDS = 3600;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Name', '');
        $this->RegisterPropertyString('Icon', 'car-side');
        $this->RegisterPropertyString('LockedText', 'Verriegelt');
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
            // Ein neuer Kilometerstand macht den Wochen-Graphen ungültig.
            if ($SenderID === $this->ReadPropertyInteger('MileageID')) {
                $this->SetBuffer('WeekCache', '');
            }
            $this->TilePush();
        }
    }

    /**
     * Bedienung aus der Kachel: 'Toggle' schaltet die Zielladen-Bools um,
     * 'SetValue' setzt den Ziel-Ladestand (geklemmt auf die Grenzen der Darstellung).
     * Es werden nur die konfigurierten Variablen mit Aktion akzeptiert.
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        if ($ident === 'Toggle') {
            $objectID = (int) $value;
            $allowed = false;
            foreach (self::TOGGLE_PROPERTIES as $property) {
                if ($this->ReadPropertyInteger($property) === $objectID) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed || !IPS_VariableExists($objectID) || IPS_GetVariable($objectID)['VariableType'] !== VARIABLETYPE_BOOLEAN || !HasAction($objectID)) {
                throw new Exception('Object not switchable: ' . $objectID);
            }
            RequestAction($objectID, !GetValueBoolean($objectID));
            $this->TilePush();
            return;
        }
        if ($ident === 'SetValue') {
            $data = is_string($value) ? json_decode($value, true) : (array) $value;
            $objectID = (int) ($data['id'] ?? 0);
            if ($objectID !== $this->ReadPropertyInteger('TargetSocID') || !IPS_VariableExists($objectID) || !HasAction($objectID)) {
                throw new Exception('Object not adjustable: ' . $objectID);
            }
            $limits = $this->SocLimits($objectID);
            $new = max($limits['min'], min($limits['max'], (float) ($data['value'] ?? 0)));
            if (IPS_GetVariable($objectID)['VariableType'] === VARIABLETYPE_INTEGER) {
                $new = (int) round($new);
            }
            RequestAction($objectID, $new);
            $this->TilePush();
            return;
        }
        throw new Exception('Invalid ident: ' . $ident);
    }

    /** Grenzen und Schrittweite des Ziel-Ladestands aus Darstellung oder Profil. */
    private function SocLimits(int $variableID): array
    {
        $min = 0.0;
        $max = 100.0;
        $step = 5.0;
        $presentation = @IPS_GetVariablePresentation($variableID);
        if (is_array($presentation)) {
            if (is_numeric($presentation['MIN'] ?? null)) {
                $min = (float) $presentation['MIN'];
            }
            if (is_numeric($presentation['MAX'] ?? null) && (float) $presentation['MAX'] > $min) {
                $max = (float) $presentation['MAX'];
            }
            if (is_numeric($presentation['STEP_SIZE'] ?? null) && (float) $presentation['STEP_SIZE'] > 0) {
                $step = (float) $presentation['STEP_SIZE'];
            }
        }
        return ['min' => $min, 'max' => $max, 'step' => $step];
    }

    private function TileFile(): string
    {
        return __DIR__ . '/tile.html';
    }

    private function TileState(): array
    {
        $levelRaw = $this->TileRaw($this->ReadPropertyInteger('LevelID'));
        $lock = $this->TileFormatted($this->ReadPropertyInteger('LockID'));
        $lockedText = trim($this->ReadPropertyString('LockedText'));

        $name = $this->ReadPropertyString('Name');
        return [
            'name'     => $name !== '' ? $name : IPS_GetName($this->InstanceID),
            'icon'     => $this->ReadPropertyString('Icon') !== '' ? $this->ReadPropertyString('Icon') : 'car-side',
            'level'    => is_numeric($levelRaw) ? (float) $levelRaw : null,
            'levelText'=> $this->TileFormatted($this->ReadPropertyInteger('LevelID')),
            'range'    => $this->TileFormatted($this->ReadPropertyInteger('RangeID')),
            'mileage'  => $this->TileFormatted($this->ReadPropertyInteger('MileageID')),
            'lock'     => $lock,
            'locked'   => $lock !== null && $lockedText !== '' ? (mb_strtolower($lock) === mb_strtolower($lockedText)) : null,
            'park'     => $this->TileFormatted($this->ReadPropertyInteger('ParkID')),
            'doors'    => $this->TileFormatted($this->ReadPropertyInteger('DoorsID')),
            'windows'  => $this->TileFormatted($this->ReadPropertyInteger('WindowsID')),
            'trunk'    => $this->TileFormatted($this->ReadPropertyInteger('TrunkID')),
            'address'  => $this->TileFormatted($this->ReadPropertyInteger('AddressID')),
            'updated'  => $this->TileFormatted($this->ReadPropertyInteger('UpdatedID')),
            'chargeMode'   => $this->TileFormatted($this->ReadPropertyInteger('ChargeModeID')),
            'chargeTarget' => $this->TileFormatted($this->ReadPropertyInteger('ChargeTargetID')),
            'phaseMode'    => $this->TileFormatted($this->ReadPropertyInteger('PhaseModeID')),
            'phasesActive' => $this->TileFormatted($this->ReadPropertyInteger('PhasesActiveID')),
            'chargeActive'  => $this->ToggleState('ChargeActiveID'),
            'targetDateUse' => $this->ToggleState('TargetDateUseID'),
            'targetSoc'     => $this->TargetSocState(),
            'targetDate'    => $this->TileFormatted($this->ReadPropertyInteger('TargetDateID')),
            'fullPower'     => $this->TileFormatted($this->ReadPropertyInteger('FullPowerID')),
            'week'     => $this->WeekGraph(),
            'open'     => $this->OpenIDs()
        ];
    }

    /** Zustand eines schaltbaren Bools für die Kachel, null wenn nicht nutzbar. */
    private function ToggleState(string $property): ?array
    {
        $id = $this->ReadPropertyInteger($property);
        if ($id <= 0 || !IPS_VariableExists($id) || IPS_GetVariable($id)['VariableType'] !== VARIABLETYPE_BOOLEAN) {
            return null;
        }
        return ['id' => $id, 'on' => GetValueBoolean($id), 'action' => HasAction($id)];
    }

    /** Ziel-Ladestand mit Grenzen und Schrittweite für den Stepper in der Kachel. */
    private function TargetSocState(): ?array
    {
        $id = $this->ReadPropertyInteger('TargetSocID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $raw = GetValue($id);
        $limits = $this->SocLimits($id);
        return [
            'id'     => $id,
            'value'  => is_numeric($raw) ? (float) $raw : 0.0,
            'text'   => GetValueFormatted($id),
            'min'    => $limits['min'],
            'max'    => $limits['max'],
            'step'   => $limits['step'],
            'action' => HasAction($id)
        ];
    }

    /**
     * Gefahrene Kilometer pro Tag der letzten 7 Tage aus dem Archiv des
     * Kilometerstands (Max-Min je Tag; bei Zähler-Aggregation direkt die Menge).
     */
    private function WeekGraph(): ?array
    {
        $variableID = $this->ReadPropertyInteger('MileageID');
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            return null;
        }
        $cache = json_decode($this->GetBuffer('WeekCache'), true);
        if (is_array($cache) && ($cache['time'] ?? 0) > time() - self::WEEK_CACHE_SECONDS && ($cache['day'] ?? '') === date('Y-m-d')) {
            return $cache['data'];
        }

        $archiveIDs = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        if (count($archiveIDs) === 0) {
            return null;
        }
        $archiveID = $archiveIDs[0];
        $start = strtotime('-6 days 00:00');
        $isCounter = @AC_GetAggregationType($archiveID, $variableID) === 1;
        $records = @AC_GetAggregatedValues($archiveID, $variableID, 1, $start, time(), 0);
        if (!is_array($records)) {
            return null;
        }
        $perDay = [];
        foreach ($records as $record) {
            $day = date('Y-m-d', (int) $record['TimeStamp']);
            $perDay[$day] = round($isCounter ? max(0.0, (float) $record['Avg']) : max(0.0, (float) $record['Max'] - (float) $record['Min']), 1);
        }
        $days = [];
        for ($i = 6; $i >= 0; $i--) {
            $timestamp = strtotime("-{$i} days 00:00");
            $days[] = ['t' => $timestamp, 'v' => $perDay[date('Y-m-d', $timestamp)] ?? 0.0];
        }
        $data = ['days' => $days];
        $this->SetBuffer('WeekCache', (string) json_encode(['time' => time(), 'day' => date('Y-m-d'), 'data' => $data]));
        return $data;
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
