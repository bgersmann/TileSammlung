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
    /** true nur während des initialen Kachelaufbaus: große Teile wie Bilder werden
     *  nur dann in den Zustand aufgenommen, Pushes bleiben schlank. */
    protected bool $tileInitial = false;

    public function GetVisualizationTile(): string
    {
        $this->tileInitial = true;
        $state = $this->TileState();
        $this->tileInitial = false;
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

    /**
     * Bild eines Medienobjekts als Data-URI, null wenn keins konfiguriert, kein Bild
     * oder zu groß (Schutz der Übertragung). Nur im initialen Zustand verwenden.
     */
    private function TileImage(int $mediaID): ?string
    {
        if ($mediaID <= 0 || !IPS_MediaExists($mediaID)) {
            return null;
        }
        $media = IPS_GetMedia($mediaID);
        if ($media['MediaType'] !== MEDIATYPE_IMAGE) {
            return null;
        }
        $content = IPS_GetMediaContent($mediaID);
        if (!is_string($content) || $content === '' || strlen($content) > 2500000) {
            return null;
        }
        $extension = strtolower(pathinfo((string) $media['MediaFile'], PATHINFO_EXTENSION));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'bmp' => 'image/bmp'][$extension] ?? 'image/png';
        return 'data:' . $mime . ';base64,' . $content;
    }
}
