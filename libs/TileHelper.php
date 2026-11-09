<?php

declare(strict_types=1);

/**
 * Gemeinsamer HTML-SDK-Unterbau der TileSammlung (Design angelehnt an UnifiNet).
 *
 * Jedes Modul liefert TileState() (die Daten der Kachel als Array) und TileFile()
 * (den Pfad zu seiner tile.html). Der Zustand geht in den Browser: nur Felder
 * aufnehmen, die auch angezeigt werden sollen.
 */
trait TileHelper
{
    public function GetVisualizationTile(): string
    {
        $state = $this->TileState();
        $state['inactive'] = $this->GetStatus() !== IS_ACTIVE;
        $json = json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return str_replace('/*INITIAL_STATE*/null', (string) $json, (string) file_get_contents($this->TileFile()));
    }

    /** Schickt den kompletten Zustand an alle offenen Visualisierungen. */
    private function TilePush(): void
    {
        $state = $this->TileState();
        $state['inactive'] = $this->GetStatus() !== IS_ACTIVE;
        $this->UpdateVisualizationValue((string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Formatierter Wert einer Variable oder null, wenn keine konfiguriert ist. */
    private function TileFormatted(int $variableID): ?string
    {
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            return null;
        }
        return GetValueFormatted($variableID);
    }

    /** Rohwert einer Variable oder null, wenn keine konfiguriert ist. */
    private function TileRaw(int $variableID): mixed
    {
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            return null;
        }
        return GetValue($variableID);
    }
}
