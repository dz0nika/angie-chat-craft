<?php

namespace Dz0nika\AngieChatCraft\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\NestedElementInterface;
use craft\commerce\elements\Product;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\fields\Assets as AssetsField;
use craft\fields\PlainText;
use craft\fields\Entries as EntriesField;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\helpers\MoneyHelper;
use Money\Money;

/**
 * Payload Builder - Extracts and cleans content from Craft entries.
 *
 * This is the most complex part of the plugin. It handles:
 * - Stripping HTML from rich text fields
 * - Flattening Matrix/nested entry blocks into readable text
 * - Extracting primary image URLs
 * - Building clean payloads for the AI engine
 *
 * The goal is to produce a single, clean string of text that the LLM
 * can understand without any Twig markup or HTML artifacts.
 */
class PayloadBuilder extends Component
{
    /** Nesting levels of blocks-in-blocks to read (page builders nest 2-3 deep). */
    private const MAX_DEPTH = 4;

    /**
     * Build a complete payload from a Craft Entry.
     */
    public function buildFromEntry(Entry $entry): array
    {
        $section = $entry->getSection();
        $entryType = $entry->getType();

        $content = $this->extractContent($entry);
        $imageUrl = $this->extractPrimaryImage($entry);

        return [
            'entry_id' => $entry->id ?? 0,
            'entry_uid' => $entry->uid ?? '',
            'site_id' => $entry->siteId ?? 1,
            // Two-letter site language, so answers use the shopper's language version.
            'language' => substr((string) $entry->getSite()->language, 0, 2),
            'title' => $entry->title ?? '',
            'url' => $entry->getUrl() ?? '',
            'content' => $content,
            'image_url' => $imageUrl,
            'section' => $section?->handle ?? 'unknown',
            'type' => $entryType?->handle ?? 'default',
            'updated_at' => $entry->dateUpdated?->format('c') ?? (new \DateTime())->format('c'),
        ];
    }

    /**
     * Build a payload from a Craft Commerce product: its own fields plus one
     * line per variant with price, sale price and availability.
     */
    public function buildFromProduct(Product $product): array
    {
        $content = trim($this->extractContent($product) . "\n\n" . $this->describeVariants($product));

        return [
            'entry_id' => $product->id ?? 0,
            'entry_uid' => $product->uid ?? '',
            'site_id' => $product->siteId ?? 1,
            // Two-letter site language, so answers use the shopper's language version.
            'language' => substr((string) $product->getSite()->language, 0, 2),
            'title' => $product->title ?? '',
            'url' => $product->getUrl() ?? '',
            'content' => $content,
            'image_url' => $this->extractPrimaryImage($product),
            'section' => 'products',
            'type' => $product->getType()->handle,
            // Stock and catalog prices change without a product save, so the
            // backend must never dedupe a product re-sync as "unchanged".
            'updated_at' => (new \DateTime())->format('c'),
        ];
    }

    private function describeVariants(Product $product): string
    {
        $lines = [];

        foreach ($product->getVariants() as $variant) {
            try {
                $currency = $variant->getStore()->getCurrency()?->getCode();
                $money = fn (?float $amount) => $amount === null
                    ? ''
                    : Craft::$app->getFormatter()->asCurrency($amount, $currency);

                $price = $money($variant->getSalePrice());
                if ($variant->getPromotionalPrice() !== null) {
                    $price .= ' (on sale, regular price ' . $money($variant->getPrice()) . ')';
                }

                $availability = match (true) {
                    ! $variant->hasStock() => 'out of stock',
                    $variant->getIsAvailable() => 'in stock',
                    default => 'not available for purchase',
                };

                $sku = $variant->getSkuAsText();

                $lines[] = '- ' . implode(' | ', array_filter([
                    $variant->title,
                    $sku !== '' ? "SKU {$sku}" : '',
                    $price,
                    $availability,
                ]));
            } catch (\Throwable $e) {
                // One broken variant must not drop the whole product
                continue;
            }
        }

        return $lines === [] ? '' : "Variants (name | SKU | price | availability):\n" . implode("\n", $lines);
    }

    /**
     * Extract all text content from an entry's fields.
     */
    public function extractContent(Element $entry): string
    {
        $parts = [];

        if ($entry->title) {
            $parts[] = $entry->title;
        }

        $fieldLayout = $entry->getFieldLayout();
        if (! $fieldLayout) {
            return implode("\n\n", $parts);
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            $value = $entry->getFieldValue($field->handle);

            if ($value === null) {
                continue;
            }

            $extracted = $this->extractFieldValue($field, $value);

            if (! empty($extracted)) {
                $parts[] = $extracted;
            }
        }

        $content = implode("\n\n", array_filter($parts));

        return $this->cleanText($content);
    }

    /**
     * Extract value from a specific field type.
     */
    private function extractFieldValue($field, $value, int $depth = 0): string
    {
        try {
            if ($field instanceof AssetsField) {
                return '';
            }

            if ($field instanceof Matrix) {
                return $this->extractMatrixContent($value, $depth);
            }

            if ($field instanceof EntriesField) {
                return $this->extractRelatedEntries($value);
            }

            if ($field instanceof PlainText) {
                return (string) $value;
            }

            // Categories, tags, nested blocks of page builders (Neo, Super
            // Table, Matrix inside Matrix...): their content or titles. Cast
            // to string, a query only gives its class name.
            if ($value instanceof ElementQueryInterface || $value instanceof ElementCollection) {
                return $this->extractElements($field->name, $value, $depth);
            }

            if (is_bool($value)) {
                return '';
            }

            // Number fields: a bare number means nothing without its label.
            if (is_int($value) || is_float($value)) {
                return "{$field->name}: {$value}";
            }

            if ($value instanceof Money) {
                return "{$field->name}: ".MoneyHelper::toString($value);
            }

            if (is_string($value)) {
                return $this->stripHtml($value);
            }

            if (is_object($value) && method_exists($value, '__toString')) {
                return $this->stripHtml((string) $value);
            }

            if (is_array($value)) {
                return $this->extractFromArray($value);
            }

            return '';
        } catch (\Throwable $e) {
            // Never crash on field extraction (third-party field types too)
            return '';
        }
    }

    /**
     * Extract content from Matrix fields (Craft 5 nested entries).
     */
    private function extractMatrixContent($matrixQuery, int $depth = 0): string
    {
        if (! $matrixQuery || $depth >= self::MAX_DEPTH) {
            return '';
        }

        $parts = [];

        try {
            // Handle ElementQuery objects safely
            if (is_object($matrixQuery) && method_exists($matrixQuery, 'all')) {
                $entries = $matrixQuery->all();
            } elseif (is_iterable($matrixQuery)) {
                $entries = $matrixQuery;
            } else {
                return '';
            }

            foreach ($entries as $block) {
                if (! $block instanceof Element) {
                    continue;
                }

                $blockContent = $this->extractBlockContent($block, $depth + 1);

                if (! empty($blockContent)) {
                    $parts[] = $blockContent;
                }
            }
        } catch (\Exception $e) {
            // Silently fail on Matrix extraction errors
            return '';
        }

        return implode("\n\n", $parts);
    }

    /**
     * Extract content from a single Matrix block (nested entry), or a block
     * of another page builder. Same rules as top-level fields, one level down.
     */
    private function extractBlockContent(Element $block, int $depth): string
    {
        $parts = [];

        $fieldLayout = $block->getFieldLayout();
        if (! $fieldLayout) {
            return '';
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            $value = $block->getFieldValue($field->handle);

            if ($value === null) {
                continue;
            }

            $extracted = trim($this->extractFieldValue($field, $value, $depth));
            if ($extracted !== '') {
                $parts[] = $extracted;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Elements in a relation or nested field: nested blocks contribute their
     * content, everything else (categories, tags, users) its title.
     */
    private function extractElements(string $label, $value, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH) {
            return '';
        }

        $elements = $value instanceof ElementQueryInterface
            ? (clone $value)->limit(50)->all()
            : $value->all();

        $titles = [];
        $blocks = [];

        foreach ($elements as $element) {
            if (! $element instanceof Element || $element instanceof Asset) {
                continue;
            }

            // Only blocks owned by this element are read in full; related
            // elements (users, categories, other entries) give just a title,
            // so nobody's profile fields end up in the AI index.
            if ($element instanceof NestedElementInterface && $element->getOwnerId() !== null) {
                $content = $this->extractBlockContent($element, $depth + 1);
                if ($content !== '') {
                    $blocks[] = $content;
                }
            } elseif ($element::hasTitles() && $element->title) {
                $titles[] = $element->title;
            }
        }

        return trim(implode("\n", array_filter([
            $titles !== [] ? "{$label}: ".implode(', ', $titles) : '',
            ...$blocks,
        ])));
    }

    /**
     * Extract titles from related entries.
     */
    private function extractRelatedEntries($entriesQuery): string
    {
        if (! $entriesQuery) {
            return '';
        }

        $titles = [];

        try {
            // Handle ElementQuery objects safely
            if (is_object($entriesQuery) && method_exists($entriesQuery, 'all')) {
                $entries = $entriesQuery->all();
            } elseif (is_iterable($entriesQuery)) {
                $entries = $entriesQuery;
            } else {
                return '';
            }

            foreach ($entries as $entry) {
                if ($entry instanceof Entry && $entry->title) {
                    $titles[] = $entry->title;
                }
            }
        } catch (\Exception $e) {
            // Silently fail on related entries extraction
            return '';
        }

        if (empty($titles)) {
            return '';
        }

        return 'Related: '.implode(', ', $titles);
    }

    /**
     * Extract text from array values.
     */
    private function extractFromArray(array $values): string
    {
        $parts = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $parts[] = $this->stripHtml($value);
            } elseif (is_array($value)) {
                $parts[] = $this->extractFromArray($value);
            }
        }

        return implode(' ', array_filter($parts));
    }

    /**
     * Extract the primary/featured image URL from an entry.
     */
    public function extractPrimaryImage(Element $entry): ?string
    {
        $fieldLayout = $entry->getFieldLayout();
        if (! $fieldLayout) {
            return null;
        }

        $commonImageHandles = [
            'featuredImage',
            'image',
            'heroImage',
            'thumbnail',
            'photo',
            'mainImage',
            'productImage',
            'coverImage',
        ];

        foreach ($commonImageHandles as $handle) {
            try {
                $value = $entry->getFieldValue($handle);

                if ($value) {
                    $asset = $this->getFirstAsset($value);
                    if ($asset) {
                        return $asset->getUrl();
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        foreach ($fieldLayout->getCustomFields() as $field) {
            if (! ($field instanceof AssetsField)) {
                continue;
            }

            try {
                $value = $entry->getFieldValue($field->handle);
                $asset = $this->getFirstAsset($value);

                if ($asset) {
                    return $asset->getUrl();
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    /**
     * Get the first asset from an asset query or collection.
     */
    private function getFirstAsset($value): ?Asset
    {
        if ($value instanceof Asset) {
            return $value;
        }

        if (is_iterable($value)) {
            foreach ($value as $item) {
                if ($item instanceof Asset) {
                    return $item;
                }
            }
        }

        if (is_object($value) && method_exists($value, 'one')) {
            $asset = $value->one();

            return $asset instanceof Asset ? $asset : null;
        }

        return null;
    }

    /**
     * Strip HTML tags and decode entities.
     * Also strips Twig syntax to avoid leaking backend code into Pinecone vectors.
     */
    private function stripHtml(string $html): string
    {
        $text = $this->stripTwig($html);

        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
        $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $text);

        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/?(h[1-6]|div|li|tr)[^>]*>/i', "\n", $text);

        $text = strip_tags($text);

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $text;
    }

    /**
     * Strip Twig template syntax before HTML stripping.
     * Prevents {% block %}, {{ variable }}, {# comments #} from leaking into vectors.
     */
    private function stripTwig(string $text): string
    {
        $text = preg_replace('/{%.*?%}/s', '', $text);
        $text = preg_replace('/{{.*?}}/s', '', $text);
        $text = preg_replace('/{#.*?#}/s', '', $text);

        return $text;
    }

    /**
     * Clean and normalize text content.
     */
    private function cleanText(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        $text = preg_replace('/[ \t]+/', ' ', $text);

        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Trim each line, but keep the line breaks: removing them glued
        // words together ("Bottesdefault-bg").
        $text = preg_replace('/^[ \t]+|[ \t]+$/m', '', $text);

        return trim($text);
    }
}
