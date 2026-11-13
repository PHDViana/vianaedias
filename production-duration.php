<?php
function vd_production_duration($minutes)
{
    if (!is_numeric($minutes) || !is_finite((float) $minutes) || (float) $minutes < 0) {
        throw new DomainException('Duração de produção inválida.');
    }
    $seconds = (int) round((float) $minutes * 60);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
}
