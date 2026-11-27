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

    private const VARIABLE_PROPERTIES = ['LevelID', 'RangeID', 'MileageID', 'LockID', 'ParkID', 'DoorsID', 'WindowsID', 'TrunkID', 'AddressID', 'UpdatedID'];

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
            $this->TilePush();
        }
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
            'open'     => $this->OpenIDs()
        ];
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
