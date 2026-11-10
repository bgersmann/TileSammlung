<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileHelper.php';

/**
 * HTML-SDK-Kachel für ein Haushaltsgerät (Waschmaschine, Trockner, Spülmaschine, ...).
 *
 * Eine Instanz zeigt genau ein Gerät: Zustand, Fortschritt, Restzeit, Leistung und
 * Verbrauch. Jede Variable ist optional - nicht konfigurierte Werte werden einfach
 * nicht angezeigt, dadurch passt die Kachel für ganz unterschiedliche Geräte.
 * Jede Änderung einer zugeordneten Variable wird sofort in die Visualisierung gepusht.
 */
class HausgeraeteKachel extends IPSModuleStrict
{
    use TileHelper;

    private const VARIABLE_PROPERTIES = ['StateID', 'ProgressID', 'RemainingID', 'EndID', 'PowerID', 'ConsumptionID', 'LastRunID'];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Name', '');
        $this->RegisterPropertyString('Icon', 'washing-machine');
        foreach (self::VARIABLE_PROPERTIES as $property) {
            $this->RegisterPropertyInteger($property, 0);
        }
        $this->RegisterPropertyString('RunningValues', '2');
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
        $variableIDs = $this->ConfiguredVariableIDs();
        foreach ($variableIDs as $variableID) {
            $this->RegisterMessage($variableID, VM_UPDATE);
            $this->RegisterReference($variableID);
        }

        $this->SetStatus(count($variableIDs) > 0 ? IS_ACTIVE : IS_INACTIVE);
        $this->TilePush();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->TilePush();
        }
    }

    /** Alle konfigurierten, existierenden Variablen-IDs (ohne Duplikate). */
    private function ConfiguredVariableIDs(): array
    {
        $ids = [];
        foreach (self::VARIABLE_PROPERTIES as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && IPS_VariableExists($id)) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private function TileFile(): string
    {
        return __DIR__ . '/tile.html';
    }

    /**
     * Läuft das Gerät? Maßgeblich ist der Rohwert der Zustandsvariable: er zählt als
     * "läuft", wenn er in der Liste RunningValues steht (kommagetrennt, Vergleich als
     * Text - passt damit für Integer-, String- und Bool-Variablen). Nur wenn keine
     * Zustandsvariable oder keine Liste konfiguriert ist, gilt ein angefangener
     * Fortschritt (1-99 %) als "läuft".
     */
    private function IsRunning(?float $progress): bool
    {
        $stateID = $this->ReadPropertyInteger('StateID');
        $runningValues = array_values(array_filter(array_map('trim', explode(',', $this->ReadPropertyString('RunningValues'))), fn ($value) => $value !== ''));
        if ($stateID > 0 && IPS_VariableExists($stateID) && count($runningValues) > 0) {
            $stateRaw = $this->TileRaw($stateID);
            $state = is_bool($stateRaw) ? ($stateRaw ? '1' : '0') : trim((string) $stateRaw);
            return in_array($state, $runningValues, true);
        }
        return $progress !== null && $progress > 0 && $progress < 100;
    }

    /** Anzeigedaten des Geräts: formatierte Werte plus Rohwerte für Balken und Restzeit. */
    private function TileState(): array
    {
        $progressRaw = $this->TileRaw($this->ReadPropertyInteger('ProgressID'));
        $progress = is_numeric($progressRaw) ? (float) $progressRaw : null;
        $remainingRaw = $this->TileRaw($this->ReadPropertyInteger('RemainingID'));
        $running = $this->IsRunning($progress);

        $name = $this->ReadPropertyString('Name');
        return [
            'name'          => $name !== '' ? $name : IPS_GetName($this->InstanceID),
            'icon'          => $this->ReadPropertyString('Icon') !== '' ? $this->ReadPropertyString('Icon') : 'washing-machine',
            'state'         => $this->TileFormatted($this->ReadPropertyInteger('StateID')),
            'running'       => $running,
            'progress'      => $progress,
            'remaining'     => is_numeric($remainingRaw) ? (float) $remainingRaw : null,
            'remainingText' => $this->TileFormatted($this->ReadPropertyInteger('RemainingID')),
            'end'           => $this->TileFormatted($this->ReadPropertyInteger('EndID')),
            'power'         => $this->TileFormatted($this->ReadPropertyInteger('PowerID')),
            'consumption'   => $this->TileFormatted($this->ReadPropertyInteger('ConsumptionID')),
            'lastRun'       => $this->TileFormatted($this->ReadPropertyInteger('LastRunID'))
        ];
    }
}
