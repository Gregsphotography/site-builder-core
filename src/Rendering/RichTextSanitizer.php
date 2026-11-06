<?php

declare(strict_types=1);

namespace SiteBuilder\Core\Rendering;

use DOMDocument;
use DOMElement;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class RichTextSanitizer
{
    public function __construct(private SafeUrl $urls) {}

    public function sanitize(string $html): string
    {
        $config = (new HtmlSanitizerConfig)->withMaxInputLength((int) config('site-builder.documents.max_bytes', 2097152))->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])->allowRelativeLinks()->allowMediaSchemes(['http', 'https'])->allowRelativeMedias();
        foreach (['div', 'section', 'article', 'address', 'small', 'button', 'label', 'details', 'summary', 'figure', 'figcaption', 'caption', 'tfoot', 'p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'sup', 'sub', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'pre', 'code', 'span', 'table', 'thead', 'tbody', 'tr', 'th', 'td'] as $element) {
            $config = $config->allowElement($element);
        }
        $config = $config->allowAttribute('id', '*')->allowElement('button', ['type'])->allowElement('label', ['for'])->allowElement('a', ['href', 'title'])->allowElement('img', ['src', 'alt', 'width', 'height'])->dropElement('script')->dropElement('style');
        $safe = (new HtmlSanitizer($config))->sanitize($html);
        if ($safe === '') {
            return '';
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$safe.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            foreach ($dom->getElementsByTagName('*') as $element) {
                if ($element instanceof DOMElement) {
                    foreach (['href', 'src'] as $attribute) {
                        if ($element->hasAttribute($attribute) && ! $this->urls->valid($element->getAttribute($attribute), $attribute === 'src')) {
                            $element->removeAttribute($attribute);
                        }
                    }
                }
            }
            $html = '';
            foreach ($dom->documentElement->childNodes as $child) {
                $html .= $dom->saveHTML($child);
            }

            return $html;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
