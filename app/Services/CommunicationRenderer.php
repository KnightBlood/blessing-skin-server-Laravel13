<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use League\CommonMark\GithubFlavoredMarkdownConverter;

class CommunicationRenderer
{
    public function render(array $data, ?string $prefix = null): string
    {
        $prefix ??= 'notice-'.bin2hex(random_bytes(6));
        $markdown = new GithubFlavoredMarkdownConverter(['allow_unsafe_links' => false]);
        if (($data['editor'] ?? 'source') === 'source') {
            $html = (string) $markdown->convertToHtml($data['source'] ?? '');
        } else {
            $html = '';
            foreach ($data['blocks'] ?? [] as $i => $block) {
                $text = (string) $markdown->convertToHtml($block['content'] ?? '');
                $title = htmlspecialchars($block['title'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $tone = in_array($block['tone'] ?? '', ['info', 'success', 'warning', 'danger', 'primary', 'secondary'], true) ? $block['tone'] : 'info';
                $html .= match ($block['type'] ?? '') {
                    'heading' => '<h3>'.$title.'</h3>',
                    'alert' => '<div class="alert alert-'.$tone.'">'.$text.'</div>',
                    'collapse' => '<div class="card card-outline card-'.$tone.'"><div class="card-header"><button type="button" class="btn btn-link text-left p-0" data-toggle="collapse" data-target="#block-'.$i.'" aria-expanded="'.(!empty($block['expanded']) ? 'true' : 'false').'">'.$title.'</button></div><div id="block-'.$i.'" class="card-body collapse'.(!empty($block['expanded']) ? ' show' : '').'">'.$text.'</div></div>',
                    'divider' => '<hr>',
                    default => $text,
                };
            }
        }

        return $this->sanitize($html, $prefix);
    }

    public function importBlocks(string $source): array
    {
        $html = $this->render(['editor' => 'source', 'source' => $source]);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="import-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $blocks = [];
        $xpath = new \DOMXPath($dom);
        $inner = function (DOMNode $node) use ($dom): string {
            $text = '';
            foreach ($node->childNodes as $child) {
                $text .= $dom->saveHTML($child);
            }

return $text;
        };
        $walk = function (DOMNode $node) use (&$walk, &$blocks, $inner, $xpath, $dom): void {
            foreach ($node->childNodes as $child) {
                if (count($blocks) >= 80) {
                    break;
                }
                if (!$child instanceof DOMElement) {
                    if (trim($child->textContent) !== '') {
                        $blocks[] = ['type' => 'text', 'content' => $child->textContent];
                    }continue;
                }
                $class = ' '.$child->getAttribute('class').' ';
                $tone = 'info';
                if (preg_match('/(?:alert-|bg-|card-)(danger|red|success|green|warning|yellow|primary|secondary|info|cyan)\b/', $class, $match)) {
                    $tone = match ($match[1]) {
                        'red' => 'danger','green' => 'success','yellow' => 'warning','cyan' => 'info',default => $match[1]
                    };
                }
                if (str_contains($class, ' card ')) {
                    $body = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," card-body ")]', $child)->item(0);
                    $header = $xpath->query('.//*[contains(concat(" ",normalize-space(@class)," ")," card-header ")]', $child)->item(0);
                    $blocks[] = ['type' => 'collapse', 'title' => mb_substr(trim($header?->textContent ?? '折叠卡片'), 0, 200), 'content' => mb_substr($body ? $inner($body) : $inner($child), 0, 10000), 'tone' => $tone, 'expanded' => $body && str_contains(' '.$body->getAttribute('class').' ', ' show ')];
                } elseif (str_contains($class, ' alert ')) {
                    $blocks[] = ['type' => 'alert', 'content' => mb_substr($inner($child), 0, 10000), 'tone' => $tone];
                } elseif (preg_match('/^h[1-6]$/', $child->tagName)) {
                    $blocks[] = ['type' => 'heading', 'title' => mb_substr($child->textContent, 0, 200)];
                } elseif ($child->tagName === 'hr') {
                    $blocks[] = ['type' => 'divider'];
                } elseif ($child->tagName === 'div') {
                    $walk($child);
                } else {
                    $blocks[] = ['type' => 'text', 'content' => mb_substr($dom->saveHTML($child), 0, 10000)];
                }
            }
        };
        if ($root = $dom->getElementById('import-root')) {
            $walk($root);
        }

return $blocks;
    }

    private function sanitize(string $html, string $prefix): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="safe-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        $root = $dom->getElementById('safe-root');
        if (!$root) {
            return '';
        }
        $ids = [];
        $index = 0;
        $clean = function (DOMNode $node) use (&$clean, &$ids, &$index, $prefix): void {
            foreach (iterator_to_array($node->childNodes) as $child) {
                if (!$child instanceof DOMElement) {
                    if ($child->nodeType === XML_COMMENT_NODE) {
                        $node->removeChild($child);
                    }continue;
                }
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'textarea', 'select', 'meta', 'link', 'base'], true)) {
                    $node->removeChild($child);
                    continue;
                }
                $clean($child);
                if (!in_array($tag, ['div', 'span', 'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'small', 'blockquote', 'pre', 'code', 'a', 'button', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'details', 'summary', 'img'], true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }$node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $name = strtolower($attribute->name);
                    $value = $attribute->value;
                    $keep = match ($name) {
                        'id' => (bool) preg_match('/\A[A-Za-z][\w-]*\z/D', $value),
                        'class' => true,
                        'title','alt' => true,
                        'href' => $tag === 'a' && (bool) preg_match('~\A(?:https?://|mailto:|/(?!/)|#[A-Za-z][\w-]*\z)~i', $value),
                        'src' => $tag === 'img' && (bool) preg_match('~\A(?:https://|/(?!/))~i', $value),
                        'type' => $tag === 'button' && $value === 'button',
                        'data-toggle' => $value === 'collapse',
                        'data-target' => (bool) preg_match('/\A#[A-Za-z][\w-]*\z/D', $value),
                        'aria-controls' => (bool) preg_match('/\A[A-Za-z][\w-]*\z/D', $value),
                        'aria-expanded' => in_array($value, ['true', 'false'], true),
                        'role' => in_array($value, ['button', 'alert'], true),
                        'open' => $tag === 'details',
                        'style' => true,
                        default => false,
                    };
                    if (!$keep) {
                        $child->removeAttribute($name);
                    }
                }
                if ($child->hasAttribute('id')) {
                    $id = $child->getAttribute('id');
                    $new = $prefix.'-'.(++$index);
                    $ids[$id] ??= $new;
                    $child->setAttribute('id', $new);
                }
                if ($child->hasAttribute('class')) {
                    $classes = array_filter(preg_split('/\s+/', $child->getAttribute('class')), fn ($class) => preg_match('/\A(?:card(?:-[\w-]+)?|alert(?:-[\w-]+)?|bg-[\w-]+|text-[\w-]+|font-weight-[\w-]+|btn(?:-[\w-]+)?|fa[srlbd]?|fa-[\w-]+|collapse|collapsed|show|table(?:-[\w-]+)?|[mp][trblxy]?-[0-5]|rounded|border(?:-[\w-]+)?)\z/D', $class));
                    $child->setAttribute('class', implode(' ', $classes));
                }
                if ($child->hasAttribute('style')) {
                    $styles = [];
                    foreach (explode(';', $child->getAttribute('style')) as $rule) {
                        $pair = explode(':', $rule, 2);
                        if (count($pair) !== 2) {
                            continue;
                        }
                        [$key,$value] = array_map('trim', $pair);
                        if (($key === 'font-weight' && preg_match('/\A(?:bold|normal|[1-9]00)\z/', $value)) || ($key === 'padding' && preg_match('/\A[0-9 px.]{1,30}\z/', $value))) {
                            $styles[] = $key.':'.$value;
                        }
                    }
                    $child->setAttribute('style', implode(';', $styles));
                }
                if ($tag === 'a') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                if ($tag === 'button') {
                    $child->setAttribute('type', 'button');
                }
                if ($tag === 'img') {
                    $child->setAttribute('class', 'img-fluid');
                }
            }
        };
        $clean($root);
        $fix = function (DOMNode $node) use (&$fix, $ids): void {
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                foreach (['data-target', 'aria-controls', 'href'] as $name) {
                    $value = $child->getAttribute($name);
                    $key = ltrim($value, '#');
                    if (($name === 'aria-controls' || str_starts_with($value, '#')) && isset($ids[$key])) {
                        $child->setAttribute($name, ($name === 'aria-controls' ? '' : '#').$ids[$key]);
                    } elseif ($name === 'data-target' || $name === 'aria-controls') {
                        $child->removeAttribute($name);
                    }
                }
                $fix($child);
            }
        };
        $fix($root);
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

return $result;
    }
}
