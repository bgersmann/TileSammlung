<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileHelper.php';

/**
 * HTML-SDK-Kachel: Raster aus Icons/Werten/Namen beliebiger Variablen und Skripte.
 *
 * Die Liste in der Konfiguration bestimmt die Reihenfolge (oben links nach unten
 * rechts). Pro Eintrag ist wählbar, ob Icon, Wert und/oder Name angezeigt werden.
 * Bool-Variablen werden per Tipp umgeschaltet, Skripte gestartet; jede Änderung
 * einer Variable wird sofort in die Visualisierung gepusht.
 */
class IconRaster extends IPSModuleStrict
{
    use TileHelper;

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Items', '[]');
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
        foreach ($this->Items() as $item) {
            $objectID = (int) ($item['ObjectID'] ?? 0);
            if ($objectID <= 0 || !IPS_ObjectExists($objectID)) {
                continue;
            }
            $count++;
            $this->RegisterReference($objectID);
            if (IPS_VariableExists($objectID)) {
                $this->RegisterMessage($objectID, VM_UPDATE);
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

    /**
     * Tipp auf eine Zelle: Bool-Variablen werden umgeschaltet, Skripte gestartet.
     * Es werden nur Objekte akzeptiert, die in der Liste konfiguriert sind.
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        if ($ident !== 'Press') {
            throw new Exception('Invalid ident: ' . $ident);
        }
        $objectID = (int) $value;
        if (!$this->IsConfigured($objectID)) {
            throw new Exception('Object not configured: ' . $objectID);
        }
        if (IPS_ScriptExists($objectID)) {
            IPS_RunScriptEx($objectID, ['SENDER' => 'TileSammlung', 'INSTANCE' => $this->InstanceID]);
            return;
        }
        if (IPS_VariableExists($objectID) && IPS_GetVariable($objectID)['VariableType'] === VARIABLETYPE_BOOLEAN) {
            $newValue = !GetValueBoolean($objectID);
            if (HasAction($objectID)) {
                RequestAction($objectID, $newValue);
            } else {
                SetValue($objectID, $newValue);
            }
            // Für Variablen ohne Ereignis-Feedback direkt pushen.
            $this->TilePush();
        }
    }

    /** Konfigurierte Einträge in Listenreihenfolge. */
    private function Items(): array
    {
        $items = json_decode($this->ReadPropertyString('Items'), true);
        return is_array($items) ? $items : [];
    }

    private function IsConfigured(int $objectID): bool
    {
        foreach ($this->Items() as $item) {
            if ((int) ($item['ObjectID'] ?? 0) === $objectID) {
                return true;
            }
        }
        return false;
    }

    private function TileFile(): string
    {
        return __DIR__ . '/tile.html';
    }

    private function TileState(): array
    {
        $items = [];
        foreach ($this->Items() as $item) {
            $objectID = (int) ($item['ObjectID'] ?? 0);
            if ($objectID <= 0 || !IPS_ObjectExists($objectID)) {
                continue;
            }
            $items[] = $this->ItemState($objectID, $item);
        }
        return ['items' => $items];
    }

    /** Anzeigedaten eines Eintrags. */
    private function ItemState(int $objectID, array $item): array
    {
        $isScript = IPS_ScriptExists($objectID);
        $isVariable = IPS_VariableExists($objectID);
        $isBool = $isVariable && IPS_GetVariable($objectID)['VariableType'] === VARIABLETYPE_BOOLEAN;

        $icon = trim((string) ($item['Icon'] ?? ''));
        if ($icon === '') {
            $icon = IPS_GetObject($objectID)['ObjectIcon'];
        }
        if ($icon === '') {
            $icon = $isScript ? 'play' : ($isBool ? 'power' : 'circle-info');
        }

        return [
            'id'       => $objectID,
            'name'     => IPS_GetName($objectID),
            'icon'     => $icon,
            'value'    => $isVariable ? GetValueFormatted($objectID) : null,
            'active'   => $isBool ? GetValueBoolean($objectID) : false,
            'action'   => $isScript || $isBool,
            'showIcon' => (bool) ($item['ShowIcon'] ?? true),
            'showValue'=> (bool) ($item['ShowValue'] ?? true) && $isVariable,
            'showName' => (bool) ($item['ShowName'] ?? true)
        ];
    }
}
