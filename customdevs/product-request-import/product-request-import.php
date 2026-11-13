<?php

function vd_request_import_process(array $command): array
{
    $deadline = (float) ($GLOBALS['vd_request_import_deadline'] ?? (microtime(true) + 25));
    $remaining = (int) floor($deadline - microtime(true));
    if ($remaining < 1) {
        throw new DomainException('A importação excedeu o tempo total permitido. Divida os documentos e tente novamente.');
    }
    $pipes = [];
    $process = proc_open(array_merge(['/usr/bin/timeout', min(25, $remaining) . 's'], $command), [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, ['LC_ALL' => 'C.UTF-8', 'OMP_THREAD_LIMIT' => '1']);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar o processamento local do documento.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status === 124) {
        throw new DomainException('O processamento do documento excedeu o tempo limite. Divida o PDF ou tente uma imagem mais nítida.');
    }
    return ['status' => $status, 'text' => $stdout === false ? '' : $stdout, 'error' => $stderr === false ? '' : $stderr];
}

function vd_request_import_write_temp(string $content, string $suffix): string
{
    $path = tempnam(sys_get_temp_dir(), 'vd-request-import-');
    if ($path === false) {
        throw new RuntimeException('Não foi possível preparar o processamento temporário do documento.');
    }
    $target = $path . $suffix;
    if (!rename($path, $target)) {
        @unlink($path);
        throw new RuntimeException('Não foi possível preparar o documento para leitura.');
    }
    if (file_put_contents($target, $content, LOCK_EX) !== strlen($content)) {
        @unlink($target);
        throw new RuntimeException('Não foi possível preparar o documento para leitura.');
    }
    return $target;
}

function vd_request_import_image_text(string $path): string
{
    $result = vd_request_import_process(['tesseract', $path, 'stdout', '-l', 'por+eng', '--psm', '6']);
    if ($result['status'] !== 0) {
        throw new DomainException('Não foi possível ler o texto da imagem. Confirme se está nítida ou cole o texto do pedido.');
    }
    return trim($result['text']);
}

function vd_request_import_pdf_text(string $path, array &$warnings): string
{
    $result = vd_request_import_process(['pdftotext', '-layout', '-nopgbrk', $path, '-']);
    $text = trim($result['text']);
    if ($result['status'] === 0 && strlen($text) >= 20) {
        return $text;
    }

    $info = vd_request_import_process(['pdfinfo', $path]);
    $pageCount = 0;
    if ($info['status'] === 0 && preg_match('/^Pages:\s+([0-9]+)/mi', $info['text'], $match)) {
        $pageCount = (int) $match[1];
    }
    if ($pageCount > 6) {
        $warnings[] = 'O PDF tem ' . $pageCount . ' páginas; foi lido o texto selecionável ou, se for digitalizado, apenas as primeiras 6. Reveja o original anexado.';
    }

    $prefix = tempnam(sys_get_temp_dir(), 'vd-request-pages-');
    if ($prefix === false) {
        throw new RuntimeException('Não foi possível preparar as páginas do PDF para OCR.');
    }
    @unlink($prefix);
    $pages = [];
    try {
        $render = vd_request_import_process([
            'pdftoppm', '-f', '1', '-l', '6', '-r', '180', '-png', $path, $prefix,
        ]);
        $pages = glob($prefix . '-*.png') ?: [];
        if ($render['status'] !== 0) {
            throw new DomainException('Não foi possível abrir o PDF. Verifique se não tem palavra-passe e tente novamente.');
        }
        natsort($pages);
        $pages = array_values($pages);
        if (!$pages) {
            throw new DomainException('O PDF não contém páginas que possam ser lidas.');
        }
        $chunks = [];
        foreach ($pages as $index => $page) {
            $pageText = vd_request_import_image_text($page);
            if ($pageText !== '') {
                $chunks[] = '[Página ' . ($index + 1) . "]\n" . $pageText;
            }
        }
        if (!$chunks) {
            throw new DomainException('Não foi possível reconhecer texto no PDF digitalizado. Tente um scan mais nítido ou cole o texto do pedido.');
        }
        return implode("\n\n", $chunks);
    } finally {
        foreach (array_unique(array_merge($pages, glob($prefix . '-*.png') ?: [])) as $page) {
            if (is_file($page) && !unlink($page)) {
                error_log('VD product request import: temporary OCR page cleanup failed.');
            }
        }
    }
}

function vd_request_import_extract(string $sourceText, array $files): array
{
    if (!preg_match('//u', $sourceText)) {
        throw new DomainException('O texto colado tem uma codificação inválida.');
    }
    $sourceText = trim(str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $sourceText));
    if (strlen($sourceText) > 100000) {
        throw new DomainException('O texto colado não pode exceder 100 KB.');
    }
    if (count($files) > 5) {
        throw new DomainException('Para importação, selecione até 5 PDFs/imagens de cada vez.');
    }
    set_time_limit(120);
    $previousDeadline = $GLOBALS['vd_request_import_deadline'] ?? null;
    $GLOBALS['vd_request_import_deadline'] = microtime(true) + 105;
    $parts = [];
    $warnings = [];
    if ($sourceText !== '') {
        $parts[] = "Texto colado:\n" . $sourceText;
    }
    try {
        foreach ($files as $file) {
            $mime = (string) ($file['mime'] ?? '');
            $name = (string) ($file['name'] ?? 'documento');
            $temporary = null;
            try {
                if ($mime === 'text/plain') {
                    $text = (string) $file['content'];
                    if (!preg_match('//u', $text)) {
                        $text = iconv('Windows-1252', 'UTF-8//IGNORE', $text);
                    }
                    if ($text === false || !preg_match('//u', $text)) {
                        throw new DomainException('O ficheiro de texto não está em UTF-8/Windows-1252.');
                    }
                } elseif ($mime === 'application/pdf') {
                    $temporary = vd_request_import_write_temp((string) $file['content'], '.pdf');
                    $text = vd_request_import_pdf_text($temporary, $warnings);
                } elseif (in_array($mime, ['image/jpeg', 'image/png', 'image/tiff'], true)) {
                    $extension = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/tiff' => '.tif'][$mime];
                    $temporary = vd_request_import_write_temp((string) $file['content'], $extension);
                    $text = vd_request_import_image_text($temporary);
                } else {
                    throw new DomainException('Para extração, use PDF, JPG, PNG, TIFF ou texto TXT; os outros documentos continuam disponíveis para anexar ao pedido.');
                }
                $text = trim($text);
                if ($text !== '') {
                    $parts[] = 'Documento: ' . $name . "\n" . $text;
                } else {
                    $warnings[] = 'Não foi reconhecido texto em «' . $name . '». O original será mantido como anexo para revisão.';
                }
            } finally {
                if ($temporary !== null && is_file($temporary) && !unlink($temporary)) {
                    error_log('VD product request import: temporary source cleanup failed.');
                }
            }
        }
    } finally {
        if ($previousDeadline === null) {
            unset($GLOBALS['vd_request_import_deadline']);
        } else {
            $GLOBALS['vd_request_import_deadline'] = $previousDeadline;
        }
    }
    $text = trim(implode("\n\n", $parts));
    if ($text === '') {
        throw new DomainException('Cole o texto do pedido ou selecione um PDF/imagem para importar.');
    }
    if (strlen($text) > 120000) {
        throw new DomainException('O texto extraído excede 120 KB. Divida o documento em partes e importe-as separadamente.');
    }
    return ['text' => $text, 'warnings' => array_values(array_unique($warnings))];
}

function vd_request_import_normalize(string $value): string
{
    // Explicit map first: iconv transliteration depends on the server locale and may turn accents into "?".
    $value = strtr(mb_strtolower($value, 'UTF-8'), [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'º' => 'o', 'ª' => 'a', '²' => '2',
    ]);
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return strtolower(trim(preg_replace('/[^a-z0-9]+/', ' ', $ascii === false ? $value : $ascii)));
}

function vd_request_import_singular(string $normalized): string
{
    return implode(' ', array_map(static function (string $word): string {
        if (strlen($word) <= 3 || preg_match('/[0-9]/', $word)) return $word;
        foreach (['oes' => 'ao', 'aes' => 'ao', 'eis' => 'el', 'ais' => 'al', 'res' => 'r', 'zes' => 'z'] as $suffix => $replacement) {
            if (substr($word, -strlen($suffix)) === $suffix) return substr($word, 0, -strlen($suffix)) . $replacement;
        }
        return substr($word, -1) === 's' ? substr($word, 0, -1) : $word;
    }, explode(' ', $normalized)));
}

function vd_request_import_aliases(): array
{
    // Keyed by the singular normalized product type name; values are extra singular normalized expressions.
    return [
        'cartao' => ['cartao de visita', 'cartao visita'],
        'flyer s' => ['flyer', 'folheto', 'panfleto'],
        'desdobravel' => ['triptico', 'diptico', 'folheto desdobravel'],
        'cartaz' => ['poster'],
        'autocolante' => ['sticker', 'vinil autocolante'],
        'tag etiqueta pendurada' => ['tag'],
        'cx cartao canelado' => ['caixa de cartao canelado', 'caixa cartao canelado', 'cartao canelado'],
        'caixa' => ['caixa de cartao'],
        'marcador de livro' => ['marcador', 'marcador de livro', 'marca livro'],
        'diploma certificado' => ['diploma', 'certificado'],
        'bilhete senha' => ['bilhete', 'senha'],
        'bloco de nota' => ['bloco', 'bloco de apontamento'],
        'saco de papel' => ['saco'],
        'calendario' => ['calendario de parede', 'calendario de mesa'],
        'obra de livro' => ['livro'],
        'menu ementa' => ['menu', 'ementa', 'carta de restaurante'],
    ];
}

function vd_request_import_contains(string $haystack, string $needle): bool
{
    return $needle !== '' && strpos($haystack, ' ' . $needle . ' ') !== false;
}

function vd_request_import_find_products(string $text, array $productTypes): array
{
    $normalized = ' ' . vd_request_import_normalize($text) . ' ';
    $singularText = ' ' . vd_request_import_singular(trim($normalized)) . ' ';
    $aliases = vd_request_import_aliases();
    $children = [];
    foreach ($productTypes as $row) {
        $parent = (int) ($row['parent_id'] ?? 0);
        if ($parent > 0) {
            $children[$parent] = true;
        }
    }
    $matches = [];
    foreach ($productTypes as $row) {
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        $needle = vd_request_import_normalize($name);
        $isParent = isset($children[$id]);
        // Generic top-level groups never describe a product on their own.
        if (!$id || strlen($needle) < 5 || in_array($needle, ['outro', 'economato', 'embalagem', 'documentos', 'publicidade'], true)) {
            continue;
        }
        $singular = vd_request_import_singular($needle);
        $best = 0;
        $position = PHP_INT_MAX;
        foreach (array_merge([$singular], $aliases[$singular] ?? []) as $variant) {
            $offset = $variant === '' ? false : strpos($singularText, ' ' . $variant . ' ');
            if ($offset !== false) {
                $best = max($best, strlen($variant));
                $position = min($position, substr_count(substr($singularText, 0, $offset + 1), ' ') - 1);
            }
        }
        if (!$best && vd_request_import_contains($normalized, $needle)) {
            $best = strlen($singular);
            $position = substr_count(substr($normalized, 0, strpos($normalized, ' ' . $needle . ' ') + 1), ' ') - 1;
        }
        if ($best) {
            // position: index of the first word where the product is mentioned.
            $matches[] = ['id' => $id, 'name' => $name, 'length' => $best, 'position' => $position,
                'parent_id' => (int) ($row['parent_id'] ?? 0), 'is_parent' => $isParent];
        }
    }
    usort($matches, static function ($left, $right) {
        return $right['length'] <=> $left['length'];
    });
    return $matches;
}

/**
 * Picks the product the text is about: a subcategory confirmed by its category first,
 * then the earliest mention, then the longest (most specific) name.
 */
function vd_request_import_choose_product(array $matches): ?array
{
    if (!$matches) return null;
    $parents = [];
    foreach ($matches as $match) {
        if ($match['is_parent']) $parents[$match['id']] = true;
    }
    $key = static function ($match) use ($parents) {
        $confirmed = !$match['is_parent'] && isset($parents[$match['parent_id']]);
        return [$confirmed ? 1 : 0, -$match['position'], $match['length'], $match['is_parent'] ? 0 : 1];
    };
    usort($matches, static function ($left, $right) use ($key) {
        return $key($right) <=> $key($left);
    });
    if (isset($matches[1]) && $key($matches[0]) === $key($matches[1])) {
        // Same name under several categories: fall back to a single matched category, if any.
        return count($parents) === 1 ? array_values(array_filter($matches, static function ($match) use ($parents) {
            return isset($parents[$match['id']]);
        }))[0] : null;
    }
    return $matches[0];
}

/**
 * Splits a request that lists several products into one text block per product.
 * Lines before the first product (greeting, reference, delivery) are shared by every block.
 */
function vd_request_import_split(string $text, array $productTypes): array
{
    $componentWords = '/^\s*(?:[-*•·]+|[0-9]+[.)]|[a-z][)])?\s*(?:capa|contracapa|miolo|interior|guardas?|papel|material|suporte|impress[aã]o|acabamentos?|formato|quantidades?|cores?|frente|verso|entrega|prazo|envio|nota|obs\.?|observa[cç][oõ]es|refer[eê]ncia|ref\.?|documento|texto colado)\b/iu';
    $header = [];
    $blocks = [];
    $current = null;
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $start = false;
        $product = null;
        if (trim($line) !== '' && !preg_match($componentWords, $line)) {
            $wordsOnly = preg_replace('/\b[0-9]+(?:[.,][0-9]+)*\b/u', ' ', $line);
            $found = array_values(array_filter(vd_request_import_find_products($wordsOnly, $productTypes), static function ($match) {
                return $match['position'] <= 2;
            }));
            usort($found, static function ($left, $right) {
                return [$left['position'], -$left['length']] <=> [$right['position'], -$right['length']];
            });
            $refines = $current !== null && array_filter($found, static function ($match) use ($current) {
                // "Agrafadas" under a "Brochuras" block, or the category of the current subcategory.
                return $match['parent_id'] === $current['product'] || $match['id'] === $current['parent'];
            });
            if ($found && !$refines) {
                $chosen = vd_request_import_choose_product($found) ?? $found[0];
                $product = $chosen['id'];
                $lineHasQuantity = isset(vd_request_import_suggestions($line, $productTypes, false)['suggestions']['quantities']);
                // A repeated mention of the same product only starts a new request when both carry a quantity.
                $start = $current === null || $product !== $current['product'] ||
                    ($lineHasQuantity && $current['quantity']);
            }
        }
        if ($start) {
            if ($current !== null) $blocks[] = $current;
            $current = ['product' => $product, 'parent' => $chosen['parent_id'], 'lines' => [$line], 'quantity' => $lineHasQuantity];
        } elseif ($current === null) {
            $header[] = $line;
        } else {
            $current['lines'][] = $line;
            if (!$current['quantity'] && preg_match('/[0-9]/', $line)) {
                $current['quantity'] = isset(vd_request_import_suggestions($line, $productTypes, false)['suggestions']['quantities']);
            }
        }
    }
    if ($current !== null) $blocks[] = $current;
    $shared = trim(implode("\n", array_filter($header, static function ($line) {
        return !preg_match('/^\s*(?:texto colado|documento)\s*:/iu', $line);
    })));
    return array_map(static function ($block) use ($shared) {
        return trim(implode("\n", $block['lines']) . ($shared !== '' ? "\n" . $shared : ''));
    }, $blocks);
}

/** Returns one suggestion set per detected request (a single item when the text describes one product). */
function vd_request_import_requests(string $text, array $productTypes): array
{
    $blocks = vd_request_import_split($text, $productTypes);
    if (count($blocks) < 2) {
        return [];
    }
    return array_map(static function ($block) use ($productTypes) {
        $parsed = vd_request_import_suggestions($block, $productTypes);
        return ['text' => $block, 'suggestions' => $parsed['suggestions'], 'warnings' => $parsed['warnings']];
    }, array_slice($blocks, 0, 20));
}

function vd_request_import_suggestions(string $text, array $productTypes, bool $detectProduct = true): array
{
    $normalized = ' ' . vd_request_import_normalize($text) . ' ';
    $singularText = ' ' . vd_request_import_singular(trim($normalized)) . ' ';
    $aliases = vd_request_import_aliases();
    $lines = preg_split('/\R/u', $text) ?: [];
    $suggestions = [];
    $warnings = [];
    $matches = $detectProduct ? vd_request_import_find_products($text, $productTypes) : [];
    $chosen = vd_request_import_choose_product($matches);
    if ($chosen) {
        $suggestions['product_type'] = $chosen['id'];
        $suggestions['product_type_name'] = $chosen['name'];
        if ($chosen['is_parent']) {
            $warnings[] = 'Categoria «' . $chosen['name'] . '» reconhecida; escolha a subcategoria.';
        }
    } elseif ($matches) {
        $warnings[] = 'Foram encontradas várias subcategorias de produto igualmente prováveis; escolha manualmente.';
    } elseif ($detectProduct) {
        $warnings[] = 'O tipo de produto não foi identificado com confiança; escolha-o manualmente.';
    }

    foreach ($lines as $line) {
        if (preg_match('/^\s*(?:produto|trabalho|designa[cç][aã]o)\s*[:\-]\s*(.{2,200})\s*$/iu', $line, $match)) {
            $suggestions['name'] = trim($match[1]);
            break;
        }
    }
    foreach ($lines as $line) {
        if (preg_match('/^\s*(?:refer[eê]ncia|ref\.?)\s*[:#\-]\s*(.{1,200})\s*$/iu', $line, $match)) {
            $suggestions['reference'] = trim($match[1]);
            break;
        }
    }

    $productWords = [];
    foreach ($productTypes as $row) {
        $word = explode(' ', vd_request_import_singular(vd_request_import_normalize((string) ($row['name'] ?? ''))))[0];
        if (strlen($word) >= 4) $productWords[$word] = true;
    }
    foreach ($aliases as $list) {
        foreach ($list as $alias) {
            $word = explode(' ', $alias)[0];
            if (strlen($word) >= 4) $productWords[$word] = true;
        }
    }
    $quantityValues = [];
    foreach ($lines as $line) {
        $quantityText = null;
        if (preg_match('/\b(?:quantidades?|qtds?\.?|tiragens?)\s*[:=]?\s*\(?\s*((?:[0-9]{1,3}(?:[. ][0-9]{3})+|[0-9]{1,9})(?:\s*(?:,|;|\/|\be\b|\bou\b)\s*(?:[0-9]{1,3}(?:[. ][0-9]{3})+|[0-9]{1,9}))*)/iu', $line, $match)) {
            $quantityText = $match[1];
        } elseif (preg_match('/(?<![\p{L}0-9.,\/])((?:[0-9]{1,3}(?:[. ][0-9]{3})+|[0-9]{1,9})(?:\s*(?:,|;|\/|\be\b|\bou\b)\s*(?:[0-9]{1,3}(?:[. ][0-9]{3})+|[0-9]{1,9}))*)\s*(?:exemplares?|unidades?|unid\.?|un\.?|uni\.?|c[oó]pias|livros?)(?=\s|$|[,.;:])/iu', $line, $match)) {
            $quantityText = $match[1];
        } elseif (preg_match_all('/(?<![\p{L}0-9.,\/])((?:[0-9]{1,3}(?:[. ][0-9]{3})+|[0-9]{1,9})(?:\s*(?:,|;|\/|\be\b|\bou\b)\s*(?:[0-9]{1,3}(?:[. ][0-9]{3})+|[0-9]{1,9}))*)\s+(?:un\.?\s+|unidades\s+de\s+)?(\p{L}[\p{L}\']*)/u', $line, $found, PREG_SET_ORDER) &&
            ($quantityText = array_reduce($found, static function ($carry, $item) use ($productWords) {
                // "Preciso de 300 postais": a number followed by a product name anywhere in the line.
                return $carry ?? (isset($productWords[vd_request_import_singular(vd_request_import_normalize($item[2]))]) ? $item[1] : null);
            })) !== null) {
            // quantity found next to a product name
        } elseif (preg_match_all('/(\p{L}[\p{L}\']*)[^0-9\n]{0,25}?[(:\-–]\s*((?:[0-9]{1,3}(?:[. ][0-9]{3})+|[0-9]{1,9})(?:\s*(?:,|;|\/|\be\b|\bou\b)\s*(?:[0-9]{1,3}(?:[. ][0-9]{3})+|[0-9]{1,9}))*)(?!\s*(?:x|×|mm|cm|gr?s?\b|gsm|pp|p[aá]g|cores?))/u', $line, $found, PREG_SET_ORDER) &&
            ($quantityText = array_reduce($found, static function ($carry, $item) use ($productWords) {
                if ($carry !== null || preg_match('/^[0-4]\s*\/\s*[0-4]$/', trim($item[2]))) return $carry;
                return isset($productWords[vd_request_import_singular(vd_request_import_normalize($item[1]))]) ? $item[2] : null;
            })) !== null) {
            // product name followed by a list of quantities
        } elseif (preg_match('/^\s*((?:[0-9]{1,3}(?:[. ][0-9]{3})+|[0-9]{1,9}))\s+(?!(?:gr?s?|gsm|g\/m|mm|cm|m|kg|p[aá]g\w*|pp|cores?|cor|x|dias?|horas?|semanas?|faces?|lados?|vias?|furos?|dobras?)(?:\s|$|[.,;:\/²]))\p{L}/iu', $line, $match)) {
            // "500 cartões", "1.000 flyers": a leading number followed by the product name.
            $quantityText = $match[1];
        } else {
            continue;
        }
        if (preg_match_all('/(?:[0-9]{1,3}(?:[. ][0-9]{3})+|[0-9]{1,9})/u', $quantityText, $tokens)) {
            foreach ($tokens[0] as $token) {
                $quantity = (int) preg_replace('/\D/', '', $token);
                if ($quantity > 0 && $quantity <= 10000000) {
                    $quantityValues[] = $quantity;
                }
            }
        }
        if ($quantityValues) {
            break;
        }
    }
    if ($quantityValues) {
        $suggestions['quantities'] = array_values(array_unique($quantityValues));
    }

    $dimensionPattern = '/\b([0-9]{2,4}(?:[.,][0-9]+)?)\s*(mm|cm)?\s*[x×]\s*([0-9]{2,4}(?:[.,][0-9]+)?)\s*(mm|cm)\b/iu';
    if (preg_match($dimensionPattern, $text, $match)) {
        $firstUnit = strtolower($match[2] ?: $match[4]);
        $secondUnit = strtolower($match[4]);
        if ($firstUnit !== $secondUnit) {
            $warnings[] = 'As unidades das dimensões não coincidem; confirme largura e altura manualmente.';
        } else {
            $factor = $secondUnit === 'cm' ? 10 : 1;
            $suggestions['width_mm'] = (float) str_replace(',', '.', $match[1]) * $factor;
            $suggestions['height_mm'] = (float) str_replace(',', '.', $match[3]) * $factor;
        }
    } elseif (preg_match('/\b([0-9]{2,4}(?:[.,][0-9]+)?)\s*[x×]\s*([0-9]{2,4}(?:[.,][0-9]+)?)\b/iu', $text, $match)) {
        // Print sizes are quoted in millimetres by default (e.g. "85x55" business cards).
        $suggestions['width_mm'] = (float) str_replace(',', '.', $match[1]);
        $suggestions['height_mm'] = (float) str_replace(',', '.', $match[2]);
        $warnings[] = 'Dimensões sem unidade (' . $match[1] . ' × ' . $match[2] . ') assumidas em milímetros; confirme.';
    } elseif (preg_match('/\bA\s*([3-6])\b/iu', $text, $match)) {
        $formats = [3 => [297, 420], 4 => [210, 297], 5 => [148, 210], 6 => [105, 148]];
        $suggestions['width_mm'] = $formats[(int) $match[1]][0];
        $suggestions['height_mm'] = $formats[(int) $match[1]][1];
        $warnings[] = 'Formato ISO A' . $match[1] . ' reconhecido; confirme a orientação.';
    }

    if (preg_match('/\b([0-9]{1,5})\s*(?:p[aá]g(?:inas?)?|pp\.?)\b/iu', $text, $match)) {
        $suggestions['total_pages'] = (int) $match[1];
    }
    foreach ($lines as $line) {
        if (preg_match('/^\s*(?:papel|material|suporte)\s*[:=]\s*(.{2,1000})\s*$/iu', $line, $match)) {
            $suggestions['paper'] = trim($match[1]);
            break;
        }
    }
    if (!isset($suggestions['paper'])) {
        $paperWord = '\\b(?:couch[eé]|offset|reciclad[oa]|kraft|cartolina|bond|autocopiativ[oa]|munken|condat|ivory|ivoire|' .
            'gc1|gc2|sbs|vergu?[eé]|texturad[oa]|nacarad[oa]|perolad[oa]|vinil|polipropileno|pvc)\\b' .
            '(?:\\s+(?:mate|matt?e|brilho|brilhante|silk|acetinad[oa]|branc[oa]|natural|premium))*';
        $grammage = '\\b[0-9]{2,3}\\s*(?:gr?s?|gsm|g\\/m2?|g\\/m²|gramas?)(?=\\s|$|[.,;:)])';
        foreach ($lines as $line) {
            foreach (preg_split('/[,;|]/u', $line) ?: [] as $segment) {
                $bounds = [];
                foreach (['/' . $paperWord . '/iu', '/' . $grammage . '/iu'] as $pattern) {
                    if (preg_match($pattern, $segment, $hit, PREG_OFFSET_CAPTURE)) {
                        $bounds[] = [$hit[0][1], $hit[0][1] + strlen($hit[0][0])];
                    }
                }
                if (!$bounds) continue;
                $start = min(array_column($bounds, 0));
                $end = max(array_column($bounds, 1));
                $suggestions['paper'] = trim(substr($segment, $start, $end - $start));
                break 2;
            }
        }
    }

    // Faces and colours are detected independently: a request usually states both.
    $ratio = null;
    if (preg_match('/(?<![0-9])([0-4])\s*[\/+]\s*([0-4])(?![0-9])/u', $text, $match) &&
        in_array($match[1] . '/' . $match[2], ['4/0', '4/4', '0/4', '1/0', '1/1', '0/1', '4/1', '1/4'], true)) {
        $ratio = $match[1] . '/' . $match[2];
    }
    if ($ratio !== null) {
        $front = (int) $ratio[0];
        $back = (int) $ratio[2];
        $suggestions['faces'] = $front && $back ? 1256 : ($front ? 1255 : 1257);
    } elseif (preg_match('/\b(?:frente\s*(?:e|\/|&)\s*verso|f\s*\/\s*v|ambos\s+os\s+lados|(?:2|duas)\s+faces|dois\s+lados)\b/iu', $text)) {
        $suggestions['faces'] = 1256;
    } elseif (preg_match('/\b(?:s[oó]\s+frente|apenas\s+(?:a\s+)?frente|(?:1|uma)\s+face|um\s+lado|s[oó]\s+um\s+lado)\b/iu', $text)) {
        $suggestions['faces'] = 1255;
    }
    if (preg_match('/\b(?:sem\s+impress[aã]o|sem\s+imprimir|n[aã]o\s+impress[oa])\b/iu', $text)) {
        $suggestions['print_mode'] = 'none';
        $suggestions['colors'] = [3804];
    } elseif (($ratio !== null && strpos($ratio, '4') !== false) ||
        preg_match('/\b(?:cmyk|quadricromia|policromia|4\s*cores|quatro\s+cores|a\s+cores|full\s*colou?r|cores\s+(?:de\s+)?processo)\b/iu', $text)) {
        $suggestions['print_mode'] = 'cmyk';
        $suggestions['colors'] = [3728, 3729, 3730, 3731];
    }
    if (preg_match('/\bPantone\s+([A-Za-z0-9][A-Za-z0-9 .-]{0,80})/iu', $text, $match)) {
        $pantone = trim(preg_split('/[,;\r\n]/u', $match[1])[0]);
        $suggestions['pantone'] = $pantone;
        $suggestions['print_mode'] = isset($suggestions['colors']) ? 'cmyk_pantone' : 'pantone';
        $suggestions['colors'] = isset($suggestions['colors']) ?
            array_values(array_unique(array_merge($suggestions['colors'], [3732]))) : [3732];
    }

    foreach ($lines as $line) {
        if (preg_match('/(?:entrega|prazo)\s*(?:pretendida|prevista|até)?\s*[:=]\s*(.{1,80})/iu', $line, $match) &&
            preg_match('/\b([0-3]?[0-9])[\/.-]([0-1]?[0-9])[\/.-](20[0-9]{2})\b/u', $match[1], $date)) {
            $day = (int) $date[1];
            $month = (int) $date[2];
            $year = (int) $date[3];
            if (checkdate($month, $day, $year)) {
                $suggestions['delivery'] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            } else {
                $warnings[] = 'A data de entrega encontrada não é válida; confirme-a manualmente.';
            }
            break;
        }
    }

    $warnings[] = 'Cliente, contacto, acabamentos e requisitos ambíguos devem ser confirmados manualmente.';
    return ['suggestions' => $suggestions, 'warnings' => array_values(array_unique($warnings))];
}
