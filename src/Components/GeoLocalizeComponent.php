<?php

declare(strict_types=1);

namespace Intrabase\Components;

use Intrabase\Security\Output;

/**
 * GeoLocalizeComponent
 *
 * Geolocation input with Leaflet map display and Nominatim geocoding.
 * Stores a JSON object with address, lat, lng fields.
 *
 * options:
 *   zoom       — initial map zoom level (default: 15)
 *   map_height — map container height in px (default: 220)
 *   placeholder — placeholder text for address input
 */
final class GeoLocalizeComponent implements ComponentInterface
{
    public function render(string $field, mixed $value, array $options = []): string
    {
        $name      = Output::attr($field);
        $id        = 'field_' . Output::attr($field);
        $zoom      = (int) ($options['zoom'] ?? 15);
        $mapHeight = (int) ($options['map_height'] ?? 220);
        $req       = !empty($options['required']) ? ' required' : '';
        $ro        = !empty($options['readonly']) ? ' readonly' : '';
        $ph        = Output::attr($options['placeholder'] ?? 'Enter address...');

        // Parse existing value
        $data = ['address' => '', 'lat' => '', 'lng' => ''];
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
        }

        $jsonVal    = Output::attr(json_encode($data, JSON_UNESCAPED_UNICODE));
        $addressVal = Output::attr($data['address']);
        $lat        = (float) ($data['lat'] ?: 0);
        $lng        = (float) ($data['lng'] ?: 0);
        $mapId      = "map_{$name}";

        $html = "<div class=\"ib-geolocalize\" data-field=\"{$name}\">"
              . "<input type=\"hidden\" name=\"{$name}\" id=\"{$id}\" value=\"{$jsonVal}\"{$req}>"
              . "<div class=\"ib-input-group\">"
              . "<input type=\"text\" id=\"geo_addr_{$name}\" class=\"ib-input ib-input--geo\""
              . " value=\"{$addressVal}\" placeholder=\"{$ph}\"{$ro}>"
              . "<button type=\"button\" class=\"ib-input-group__suffix ib-btn ib-btn--icon\" id=\"geo_btn_{$name}\""
              . " title=\"Geolocalize\"><i class=\"fa-solid fa-location-dot\" aria-hidden=\"true\"></i></button>"
              . "</div>"
              . "<div id=\"{$mapId}\" class=\"ib-geolocalize__map\" style=\"height:{$mapHeight}px;margin-top:8px\"></div>"
              . "</div>";

        // Inline JS for Leaflet map and Nominatim geocoding
        $html .= "<script>"
               . "(function(){"
               . "var hidden=document.getElementById('{$id}'),"
               . "addrInput=document.getElementById('geo_addr_{$name}'),"
               . "btn=document.getElementById('geo_btn_{$name}'),"
               . "lat={$lat},lng={$lng},zoom={$zoom},"
               . "mapEl=document.getElementById('{$mapId}'),map,marker;"
               . "function initMap(){"
               . "if(typeof L==='undefined')return;"
               . "var center=lat&&lng?[lat,lng]:[41.3874,2.1686];"
               . "map=L.map('{$mapId}').setView(center,lat&&lng?zoom:4);"
               . "L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'OSM'}).addTo(map);"
               . "if(lat&&lng){marker=L.marker(center).addTo(map);}"
               . "map.on('click',function(e){updatePos(e.latlng.lat,e.latlng.lng);});"
               . "}"
               . "function updatePos(lt,ln){"
               . "lat=lt;lng=ln;"
               . "var d=JSON.parse(hidden.value||'{}');"
               . "d.lat=lat;d.lng=lng;d.address=addrInput.value;"
               . "hidden.value=JSON.stringify(d);"
               . "if(map){"
               . "if(marker)map.removeLayer(marker);"
               . "marker=L.marker([lat,lng]).addTo(map);map.setView([lat,lng],zoom);"
               . "}}"
               . "btn.addEventListener('click',function(){"
               . "var addr=addrInput.value.trim();if(!addr)return;"
               . "fetch('https://nominatim.openstreetmap.org/search?format=json&q='+encodeURIComponent(addr))"
               . ".then(function(r){return r.json()}).then(function(results){"
               . "if(results&&results.length){updatePos(parseFloat(results[0].lat),parseFloat(results[0].lon));"
               . "var d=JSON.parse(hidden.value||'{}');d.address=addr;hidden.value=JSON.stringify(d);}"
               . "});"
               . "});"
               . "addrInput.addEventListener('change',function(){"
               . "var d=JSON.parse(hidden.value||'{}');d.address=this.value;hidden.value=JSON.stringify(d);});"
               . "if(document.readyState==='complete')initMap();else window.addEventListener('load',initMap);"
               . "})();"
               . "</script>";

        return $html;
    }

    public function display(string $field, mixed $value, array $options = [], ?int $rowId = null): string
    {
        $val = trim((string) $value);
        if ($val === '') return '';

        $data = json_decode($val, true);
        if (!is_array($data)) return Output::e($val);

        $address = trim((string) ($data['address'] ?? ''));
        $lat     = (float) ($data['lat'] ?? 0);
        $lng     = (float) ($data['lng'] ?? 0);

        $parts = [];
        if ($address !== '') {
            $parts[] = Output::e($address);
        }

        if ($lat !== 0.0 && $lng !== 0.0) {
            $coords   = round($lat, 6) . ', ' . round($lng, 6);
            $mapsUrl  = "https://www.google.com/maps?q={$lat},{$lng}";
            $safeUrl  = Output::url($mapsUrl);
            $parts[] = "<a href=\"{$safeUrl}\" target=\"_blank\" rel=\"noopener\" class=\"ib-geo-link\">"
                     . "<i class=\"fa-solid fa-map-location-dot\" aria-hidden=\"true\"></i> "
                     . Output::e($coords) . "</a>";
        }

        return implode(' &mdash; ', $parts);
    }

    public function searchFilter(string $field, string $searchValue, string $operator, array $options = []): ?array
    {
        // Search by address within JSON
        return [
            'where'  => "`{$field}` LIKE ?",
            'params' => ["%{$searchValue}%"],
        ];
    }

    public function sanitize(string $field, mixed $raw, array $options = []): mixed
    {
        $str = trim((string) $raw);
        if ($str === '') return '';

        $data = json_decode($str, true);
        if (!is_array($data)) return '';

        // Only keep expected fields
        $clean = [
            'address' => trim((string) ($data['address'] ?? '')),
            'lat'     => isset($data['lat']) ? (float) $data['lat'] : null,
            'lng'     => isset($data['lng']) ? (float) $data['lng'] : null,
        ];

        // Validate latitude range (-90 to 90) and longitude range (-180 to 180)
        if ($clean['lat'] !== null && ($clean['lat'] < -90 || $clean['lat'] > 90)) {
            $clean['lat'] = null;
        }
        if ($clean['lng'] !== null && ($clean['lng'] < -180 || $clean['lng'] > 180)) {
            $clean['lng'] = null;
        }

        return json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    public function validate(string $field, mixed $value, bool $required, array $options = []): array
    {
        $str   = trim((string) $value);
        $label = $options['label'] ?? $field;

        if ($required && $str === '') {
            return ["{$label} is required."];
        }

        if ($str !== '') {
            $data = json_decode($str, true);
            if (!is_array($data)) {
                return ["{$label} contains invalid geolocation data."];
            }
        }

        return [];
    }
}
