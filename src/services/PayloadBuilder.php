<?php

namespace Dz0nika\AngieChatCraft\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\fields\Assets as AssetsField;
use craft\fields\PlainText;
use craft\fields\Entries as EntriesField;

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
     * Extract all text content from an entry's fields.
     */
    public function extractContent(Entry $entry): string
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
    private function extractFieldValue($field, $value): string
    {
        try {
            if ($field instanceof Matrix) {
                return $this->extractMatrixContent($value);
            }

            if ($field instanceof EntriesField) {
                return $this->extractRelatedEntries($value);
            }

            if ($field instanceof AssetsField) {
                return '';
            }

            if ($field instanceof PlainText) {
                return (string) $value;
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
        } catch (\Exception $e) {
            // Never crash on field extraction
            return '';
        }
    }

    /**
     * Extract content from Matrix fields (Craft 5 nested entries).
     */
    private function extractMatrixContent($matrixQuery): string
    {
        if (! $matrixQuery) {
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
                if (! $block instanceof Entry) {
                    continue;
                }

                $blockContent = $this->extractBlockContent($block);

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
     * Extract content from a single Matrix block (nested entry).
     */
    private function extractBlockContent(Entry $block): string
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

            if ($field instanceof AssetsField) {
                continue;
            }

            if (is_string($value)) {
                $cleaned = $this->stripHtml($value);
                if (! empty($cleaned)) {
                    $parts[] = $cleaned;
                }
            } elseif (is_object($value) && method_exists($value, '__toString')) {
                $cleaned = $this->stripHtml((string) $value);
                if (! empty($cleaned)) {
                    $parts[] = $cleaned;
                }
            }
        }

        return implode(' ', $parts);
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
    public function extractPrimaryImage(Entry $entry): ?string
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

        $text = preg_replace('/^\s+|\s+$/m', '', $text);

        return trim($text);
    }
}
