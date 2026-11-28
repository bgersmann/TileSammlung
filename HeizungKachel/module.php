<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/TileHelper.php';

/**
 * HTML-SDK-Kachel: Heizungsübersicht mit Heizkurve.
 *
 * Zeigt Kessel-, Vorlauf-, Rücklauf- und Warmwassertemperaturen, Betriebsart,
 * Brenner und Pumpe. Neigung und Niveau der Heizkurve sind in der Kachel über
 * +/- verstellbar (Grenzen und Schrittweite aus Darstellung bzw. Profil); bei
 * ausreichend Platz zeichnet die Kachel die Heizkurve mit dem aktuellen
 * Betriebspunkt. Jede Variable ist optional; Klick auf einen Wert öffnet sie.
 */
class HeizungKachel extends IPSModuleStrict
{
    use TileHelper;

    private const VARIABLE_PROPERTIES = ['BoilerID', 'BoilerSetID', 'FlowID', 'ReturnID', 'OutsideID', 'ModeID', 'BurnerID', 'BurnerStartsID', 'PumpID', 'WaterID', 'WaterSetID', 'RoomSetID', 'SlopeID', 'LevelID'];
    private const ADJUST_PROPERTIES = ['SlopeID', 'LevelID'];

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Name', '');
        $this->RegisterPropertyString('Icon', 'fire');
        $this->RegisterPropertyString('BurnerActiveText', 'Aktiv');
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

    /**
     * Verstellen von Neigung/Niveau aus der Kachel: nur die konfigurierten
     * Variablen mit Aktion, geklemmt auf die Grenzen aus Darstellung/Profil.
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        if ($ident !== 'SetValue') {
            throw new Exception('Invalid ident: ' . $ident);
        }
        $data = is_string($value) ? json_decode($value, true) : (array) $value;
        $objectID = (int) ($data['id'] ?? 0);
        $allowed = false;
        foreach (self::ADJUST_PROPERTIES as $property) {
            if ($this->ReadPropertyInteger($property) === $objectID) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed || !IPS_VariableExists($objectID) || !HasAction($objectID)) {
            throw new Exception('Object not adjustable: ' . $objectID);
        }
        $limits = $this->NumberLimits($objectID, 0.0, 10.0, 1.0);
        $new = max($limits['min'], min($limits['max'], (float) ($data['value'] ?? 0)));
        if (IPS_GetVariable($objectID)['VariableType'] === VARIABLETYPE_INTEGER) {
            $new = (int) round($new);
        }
        RequestAction($objectID, $new);
        $this->TilePush();
    }

    /** Grenzen und Schrittweite aus der Darstellung, sonst aus dem klassischen Profil. */
    private function NumberLimits(int $variableID, float $min, float $max, float $step): array
    {
        $presentation = @IPS_GetVariablePresentation($variableID);
        $profileName = '';
        if (is_array($presentation)) {
            if (is_numeric($presentation['MIN'] ?? null)) {
                $min = (float) $presentation['MIN'];
            }
            if (is_numeric($presentation['MAX'] ?? null)) {
                $max = (float) $presentation['MAX'];
            }
            if (is_numeric($presentation['STEP_SIZE'] ?? null) && (float) $presentation['STEP_SIZE'] > 0) {
                $step = (float) $presentation['STEP_SIZE'];
            }
            $profileName = trim((string) ($presentation['PROFILE'] ?? ''));
        }
        $variable = IPS_GetVariable($variableID);
        foreach ([(string) $variable['VariableCustomProfile'], (string) $variable['VariableProfile'], $profileName] as $candidate) {
            if ($candidate !== '' && IPS_VariableProfileExists($candidate)) {
                $profile = IPS_GetVariableProfile($candidate);
                if ((float) $profile['MaxValue'] > (float) $profile['MinValue']) {
                    $min = (float) $profile['MinValue'];
                    $max = (float) $profile['MaxValue'];
                }
                if ((float) $profile['StepSize'] > 0) {
                    $step = (float) $profile['StepSize'];
                }
                break;
            }
        }
        if ($max <= $min) {
            $max = $min + 1;
        }
        return ['min' => $min, 'max' => $max, 'step' => $step];
    }

    /** Verstellbarer Zahlenwert für die Kachel, null wenn nicht konfiguriert. */
    private function NumberState(string $property, float $min, float $max, float $step): ?array
    {
        $id = $this->ReadPropertyInteger($property);
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $raw = GetValue($id);
        $limits = $this->NumberLimits($id, $min, $max, $step);
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

    private function TileFile(): string
    {
        return __DIR__ . '/tile.html';
    }

    private function TileState(): array
    {
        $burner = $this->TileFormatted($this->ReadPropertyInteger('BurnerID'));
        $activeText = trim($this->ReadPropertyString('BurnerActiveText'));
        $outsideRaw = $this->TileRaw($this->ReadPropertyInteger('OutsideID'));
        $flowRaw = $this->TileRaw($this->ReadPropertyInteger('FlowID'));
        $boilerRaw = $this->TileRaw($this->ReadPropertyInteger('BoilerID'));
        $roomSetRaw = $this->TileRaw($this->ReadPropertyInteger('RoomSetID'));

        $name = $this->ReadPropertyString('Name');
        $state = [
            'name'       => $name !== '' ? $name : IPS_GetName($this->InstanceID),
            'icon'       => $this->ReadPropertyString('Icon') !== '' ? $this->ReadPropertyString('Icon') : 'fire',
            'boiler'     => $this->TileFormatted($this->ReadPropertyInteger('BoilerID')),
            'boilerSet'  => $this->TileFormatted($this->ReadPropertyInteger('BoilerSetID')),
            'flow'       => $this->TileFormatted($this->ReadPropertyInteger('FlowID')),
            'return'     => $this->TileFormatted($this->ReadPropertyInteger('ReturnID')),
            'outside'    => $this->TileFormatted($this->ReadPropertyInteger('OutsideID')),
            'outsideRaw' => is_numeric($outsideRaw) ? (float) $outsideRaw : null,
            'flowRaw'    => is_numeric($flowRaw) ? (float) $flowRaw : (is_numeric($boilerRaw) ? (float) $boilerRaw : null),
            'mode'       => $this->TileFormatted($this->ReadPropertyInteger('ModeID')),
            'burner'     => $burner,
            'burnerOn'   => $burner !== null && $activeText !== '' ? (mb_strtolower($burner) === mb_strtolower($activeText)) : null,
            'starts'     => $this->TileFormatted($this->ReadPropertyInteger('BurnerStartsID')),
            'pump'       => $this->TileFormatted($this->ReadPropertyInteger('PumpID')),
            'water'      => $this->TileFormatted($this->ReadPropertyInteger('WaterID')),
            'waterSet'   => $this->TileFormatted($this->ReadPropertyInteger('WaterSetID')),
            'roomSet'    => $this->TileFormatted($this->ReadPropertyInteger('RoomSetID')),
            'roomSetRaw' => is_numeric($roomSetRaw) ? (float) $roomSetRaw : null,
            'slope'      => $this->NumberState('SlopeID', 0.2, 3.5, 0.1),
            'level'      => $this->NumberState('LevelID', -13.0, 40.0, 1.0),
            'open'       => $this->OpenIDs()
        ];
        return $state;
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
