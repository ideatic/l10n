<?php

declare(strict_types=1);

namespace ideatic\l10n\Angular;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Exception;

/**
 * Reads the messages of an XLIFF 2 file written by `ng extract-i18n --format xlf2`.
 *
 * XLIFF 2 is used instead of JSON because it also reports the original source of each placeholder (disp attribute),
 * which is needed to link the nested ICU messages with the message that holds them.
 */
final class Xliff2Reader
{
    /**
     * @return array<string, Message>
     */
    public static function read(string $xml): array
    {
        $document = new DOMDocument();
        if (!@$document->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE)) {
            throw new Exception('Invalid XLIFF 2 file');
        }

        $messages = [];
        foreach ($document->getElementsByTagName('unit') as $unit) {
            $message = new Message();
            $message->id = $unit->getAttribute('id');

            foreach ($unit->getElementsByTagName('note') as $note) {
                $category = $note->getAttribute('category');
                if ($category == 'location') {
                    $file = preg_replace('/:[\d,]+$/', '', $note->textContent);
                    if (!in_array($file, $message->files)) {
                        $message->files[] = $file;
                    }
                } elseif ($category == 'meaning') {
                    $message->meaning = $note->textContent;
                }
            }

            $source = $unit->getElementsByTagName('source')->item(0);
            if (!$source) {
                throw new Exception("Source not found for message {$message->id}");
            }

            $text = '';
            foreach ($source->childNodes as $child) {
                $text .= self::_serialize($child, $message);
            }
            $message->tokens = Tokenizer::angular($text);

            $messages[$message->id] = $message;
        }

        return $messages;
    }

    private static function _serialize(DOMNode $node, Message $message): string
    {
        if ($node instanceof DOMText) {
            return $node->nodeValue ?? '';
        } elseif (!$node instanceof DOMElement) {
            return '';
        }

        if ($node->localName == 'ph') {
            $name = $node->getAttribute('equiv');
            if ($node->hasAttribute('disp')) {
                $message->displays[$name] = $node->getAttribute('disp');
            }
            return Tokenizer::MARK_PH . $name . Tokenizer::MARK_END;
        } elseif ($node->localName == 'pc') {
            $content = '';
            foreach ($node->childNodes as $child) {
                $content .= self::_serialize($child, $message);
            }

            return Tokenizer::MARK_OPEN . $node->getAttribute('equivStart') . Tokenizer::MARK_END
                . $content
                . Tokenizer::MARK_CLOSE . $node->getAttribute('equivEnd') . Tokenizer::MARK_END;
        }

        throw new Exception("Unexpected XLIFF 2 element <{$node->localName}> in message {$message->id}");
    }
}
