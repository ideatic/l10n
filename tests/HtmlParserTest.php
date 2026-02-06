<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;


class HtmlParserTest extends TestCase
{

  public function testBasic()
  {
    $input = '<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Title</title>
  <base href="/">
  <meta name="viewport" content="initial-scale=1, maximum-scale=1, user-scalable=no, width=device-width">
</head>
<body>
</body>
</html>';

    $dom = HTML_Parser::parse($input);

    $this->assertTrue($dom->findAll('body')[0] instanceof HTML_Parser_Element);
    $this->assertEquals($input, $dom->render());
  }

  public function testBasic2()
  {
    $input = '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Chuletator</title><base href="/"><meta name="viewport" content="initial-scale=1, maximum-scale=1, user-scalable=no, width=device-width"><link href="https://fonts.googleapis.com/css?family=Open+Sans:300,400,500,600,700,800,300italic,400italic,700italic,800italic" rel="stylesheet"><link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet"><link rel="manifest" href="manifest.webmanifest"><meta name="theme-color" content="#1976d2"><link rel="icon" type="image/png" sizes="192x192" href="/assets/app/assets/images/icon-192x192.png"><link rel="icon" type="image/png" sizes="192x192" href="/assets/app/assets/images/icon-96x96.png"><link rel="icon" type="image/x-icon" href="favicon.ico"><link rel="stylesheet" href="/assets/app/es/styles.194d4444cfa18a8d36d6.css"></head><body></body></html>';

    $dom = HTML_Parser::parse($input);

    $this->assertEquals($input, $dom->render());
  }

  public function testAngular()
  {
    $input = '<div #mainContainer class="tab-content" [style.min-height.px]="sameHeight ? tabHeight : undefined">
          <ng-content/>
      </div>
      <>
      < invalidTag>
      @if (!(autoHideDots && tabs.length <= 1)) {
          <ol role="tablist">
              <li *ngFor="let tab of tabs" role="tab" (click)="selectTab(tab)" [class.active]="tab.active" [attr.aria-selected]="tab.active" [attr.aria-label]="tab.header"></li>
          </ol>
      }';

    $dom = HTML_Parser::parse($input, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testAngularTemplates()
  {
    $input = '@for (network of preparedNetworks(); track network) {
      @if (network.icon.startsWith(\'<svg\')) {
        <a target="_blank" [href]="network.url" [title]="network.name" [innerHTML]="network.icon | safe: \'html\'" (click)="trackClick(network.url)"> </a>
      } @else {
        <a target="_blank" [href]="network.url" [title]="network.name" (click)="trackClick(network.url)">
          <i [icon]="network.icon"></i> I\'m
        </a>
      }
    }';

    $dom = HTML_Parser::parse($input, true, null, true, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testAngularAdvancesTemplates()
  {
    $input = '@for (minute of minutes(); track minute) {
  @switch (minute.type) {
    <!-- Separador -->
    @case (\'break\') {
      <div style.background-image="url(\'{{ \'img/sports/football/game-bg.jpg\' | asset }}\')" class="break">
        <div>{{ minute.name }}</div>
      </div>
    }

    <!-- Evento -->
    @default {
      @for (event of minute.events; track event) {
        <div class="event" [class]="event.home ? \'home-event\' : \'away-event\'" [class.last-in-minute]="$last">
          <div class="side home">
            @if (event.home) {
              <div>
                <player-link
                  icon="sm"
                  [player]="event.report.player"
                  [modalComponent]="null"
                  (click)="$event.preventDefault(); parent.showPlayerBreakdown(event.report)"
                />
              </div>
            }
          </div>
          <div class="minute" [class.emphasis]="goals.includes(event.event.type)">
            @if (minute.minute != 999) {
              {{ minute.minute | number }}\'
            }
            <player-events [events]="[event.event]" />
          </div>
          <div class="side away">
            @if (!event.home) {
              <div>
                <player-link
                  [player]="event.report.player"
                  [icon]="false"
                  [modalComponent]="null"
                  (click)="$event.preventDefault(); parent.showPlayerBreakdown(event.report)"
                />
                <player-link
                  icon="sm"
                  class="away-photo"
                  [player]="event.report.player"
                  [name]="false"
                  [modalComponent]="null"
                  (click)="$event.preventDefault(); parent.showPlayerBreakdown(event.report)"
                />
              </div>
            }
          </div>
        </div>
      }
    }
  }
}
@if (totalEvents() <= 4) {
  <ads section="game-events" zone="content" mobile="vertical" />
}
';

    $dom = HTML_Parser::parse($input, true, null, true, true);
    $this->assertEquals($input, $dom->render());
  }

  // Tests adicionales para edge cases

  public function testSelfClosingTags()
  {
    $input = '<div><br><hr><img src="test.jpg"><input type="text"></div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testSelfClosingTagsWithSlash()
  {
    $input = '<div><br/><hr /><img src="test.jpg" /><input type="text"/></div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testNestedElements()
  {
    $input = '<div><div><div><span>deep</span></div></div></div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testComments()
  {
    $input = '<div><!-- This is a comment --><span>text</span><!-- Another comment --></div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testMultilineComments()
  {
    $input = '<div><!--
      This is a
      multiline comment
    --><span>text</span></div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testAttributesWithSpecialCharacters()
  {
    $input = '<div data-value="a > b" title="quotes: &quot;test&quot;">content</div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testAttributesWithSingleQuotes()
  {
    $input = "<div class='single-quoted' data-json='{\"key\": \"value\"}'>content</div>";
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testAttributesWithoutQuotes()
  {
    $input = '<div class=test id=myid data-num=123>content</div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testBooleanAttributes()
  {
    $input = '<input type="checkbox" checked disabled readonly>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testEmptyElements()
  {
    $input = '<div></div><span></span><p></p>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testMixedContent()
  {
    $input = '<div>text <b>bold</b> more text <i>italic</i> end</div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testWhitespacePreservation()
  {
    $input = '<pre>
    function test() {
        return true;
    }
</pre>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testScriptTag()
  {
    $input = '<script>
      if (a < b && b > c) {
        console.log("<div>not html</div>");
      }
    </script>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testStyleTag()
  {
    $input = '<style>
      .class > .child {
        color: red;
      }
    </style>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testDoctype()
  {
    $input = '<!DOCTYPE html><html><head></head><body></body></html>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testDoctypeWithAttributes()
  {
    $input = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd"><html></html>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testTextOnlyContent()
  {
    $input = 'Just plain text without any HTML tags';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testTextWithLessThan()
  {
    $input = 'a < b and c > d';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testAngularBindings()
  {
    $input = '<div [class.active]="isActive" (click)="onClick($event)" [(ngModel)]="value">{{ interpolation }}</div>';
    $dom = HTML_Parser::parse($input, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testAngularStructuralDirectives()
  {
    $input = '<div *ngIf="condition" *ngFor="let item of items; trackBy: trackFn">{{ item }}</div>';
    $dom = HTML_Parser::parse($input, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testVueBindings()
  {
    $input = '<div :class="activeClass" @click="handleClick" v-model="value">{{ message }}</div>';
    $dom = HTML_Parser::parse($input, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testCustomElements()
  {
    $input = '<my-component prop="value"><slot-content></slot-content></my-component>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testSvgElements()
  {
    $input = '<svg viewBox="0 0 100 100"><circle cx="50" cy="50" r="40"/></svg>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testMultilineAttributes()
  {
    $input = '<div
  class="container"
  id="main"
  data-config="{
    key: value
  }"
>content</div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testAttributesWithNewlinesInValues()
  {
    $input = '<div title="Line 1
Line 2
Line 3">content</div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testEmptyAttributeValue()
  {
    $input = '<div class="" data-empty="">content</div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testSpecialTagNames()
  {
    $input = '<h1>Header</h1><h2>Sub</h2><h3>SubSub</h3><article><section><aside>Side</aside></section></article>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testTableStructure()
  {
    $input = '<table><thead><tr><th>Header</th></tr></thead><tbody><tr><td>Cell</td></tr></tbody></table>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testFormElements()
  {
    $input = '<form action="/submit" method="post"><label for="name">Name:</label><input type="text" id="name" name="name"><select name="option"><option value="1">One</option><option value="2" selected>Two</option></select><textarea name="message">Default text</textarea><button type="submit">Submit</button></form>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testDataAttributes()
  {
    $input = '<div data-id="123" data-user-name="John Doe" data-config=\'{"enabled": true}\'>content</div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testUnicodeContent()
  {
    $input = '<div>日本語テスト</div><span>Ñoño español</span><p>Emoji: 🎉🚀💻</p>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testUnicodeInAttributes()
  {
    $input = '<div title="日本語" data-emoji="🎉">content</div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testInvalidTagTreatedAsText()
  {
    $input = '<div><></div>';
    $dom = HTML_Parser::parse($input, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testTagWithSpaceAfterLessThan()
  {
    $input = '<div>< not a tag</div>';
    $dom = HTML_Parser::parse($input, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testConsecutiveComments()
  {
    $input = '<!-- comment 1 --><!-- comment 2 --><div>content</div><!-- comment 3 -->';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testAngularPipes()
  {
    $input = '<div>{{ value | currency:\'EUR\' | uppercase }}</div>';
    $dom = HTML_Parser::parse($input, true, null, true, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testAngularConditionalClasses()
  {
    $input = '<div [class.active]="isActive" [class.disabled]="!isEnabled" [ngClass]="{\'highlight\': isHighlighted}">content</div>';
    $dom = HTML_Parser::parse($input, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testMixedQuotesInExpressions()
  {
    $input = '<div [attr.title]="\'prefix\' + value + \'suffix\'">content</div>';
    $dom = HTML_Parser::parse($input, true, null, true, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testAngularEventWithDollarSign()
  {
    $input = '<button (click)="handleClick($event, \'param\')">Click</button>';
    $dom = HTML_Parser::parse($input, true, null, true, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testNestedAngularExpressions()
  {
    $input = '<div *ngIf="items?.length > 0">{{ items[0]?.name || \'default\' }}</div>';
    $dom = HTML_Parser::parse($input, true, null, true, true);
    $this->assertEquals($input, $dom->render());
  }

  public function testTrailingWhitespace()
  {
    $input = '<div>content</div>   ';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testLeadingWhitespace()
  {
    $input = '   <div>content</div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testFindAllMethod()
  {
    $input = '<div><span class="a">1</span><span class="b">2</span><p><span>3</span></p></div>';
    $dom = HTML_Parser::parse($input);

    $spans = $dom->findAll('span');
    $this->assertCount(3, $spans);

    $divs = $dom->findAll('div');
    $this->assertCount(1, $divs);
  }

  public function testEmptyDocument()
  {
    $input = '';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testOnlyWhitespace()
  {
    $input = '   
  
   ';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  // Tests para preserveWhitespace flag

  public function testPreserveWhitespaceDefaultTrue()
  {
    $input = '<div
  class="container"
  id="main"
>content</div>';
    $dom = HTML_Parser::parse($input);
    $this->assertEquals($input, $dom->render());
  }

  public function testNormalizeWhitespaceMultilineAttributes()
  {
    $input = '<div
  class="container"
  id="main"
>content</div>';
    $expected = '<div class="container" id="main">content</div>';
    $dom = HTML_Parser::parse($input, false, null, false, false);
    $this->assertEquals($expected, $dom->render());
  }

  public function testNormalizeWhitespaceTagWithNewlineBeforeClose()
  {
    $input = '<strong
>text</strong>';
    $expected = '<strong>text</strong>';
    $dom = HTML_Parser::parse($input, false, null, false, false);
    $this->assertEquals($expected, $dom->render());
  }

  public function testPreserveWhitespaceTagWithNewlineBeforeClose()
  {
    $input = '<strong
>text</strong>';
    $dom = HTML_Parser::parse($input, false, null, true, false);
    $this->assertEquals($input, $dom->render());
  }

  public function testNormalizeWhitespaceSelfClosingTag()
  {
    $input = '<img
  src="image.jpg"
  alt="description"
/>';
    $expected = '<img src="image.jpg" alt="description"/>';
    $dom = HTML_Parser::parse($input, false, null, false, false);
    $this->assertEquals($expected, $dom->render());
  }

  public function testPreserveWhitespaceSelfClosingTag()
  {
    $input = '<img
  src="image.jpg"
  alt="description"
/>';
    $dom = HTML_Parser::parse($input, false, null, true, false);
    $this->assertEquals($input, $dom->render());
  }

  public function testNormalizeWhitespaceMultipleSpaces()
  {
    $input = '<div    class="test"     id="main"    >content</div>';
    $expected = '<div class="test" id="main">content</div>';
    $dom = HTML_Parser::parse($input, false, null, false, false);
    $this->assertEquals($expected, $dom->render());
  }

  public function testNormalizeWhitespaceTabs()
  {
    $input = "<div\tclass=\"test\"\tid=\"main\">content</div>";
    $expected = '<div class="test" id="main">content</div>';
    $dom = HTML_Parser::parse($input, false, null, false, false);
    $this->assertEquals($expected, $dom->render());
  }

  public function testPreserveWhitespaceTabs()
  {
    $input = "<div\tclass=\"test\"\tid=\"main\">content</div>";
    $dom = HTML_Parser::parse($input, false, null, true, false);
    $this->assertEquals($input, $dom->render());
  }

  public function testNormalizeWhitespaceAngularTemplate()
  {
    $input = '<player-link
                  icon="sm"
                  [player]="event.report.player"
                  [modalComponent]="null"
                />';
    $expected = '<player-link icon="sm" [player]="event.report.player" [modalComponent]="null"/>';
    $dom = HTML_Parser::parse($input, false, null, false, false);
    $this->assertEquals($expected, $dom->render());
  }

  public function testNormalizeWhitespaceComplexAngularTemplate()
  {
    $input = '@for (item of items; track item) {
  <div
    class="item"
    [class.active]="item.active"
  >{{ item.name }}</div>
}';
    $expected = '@for (item of items; track item) {
  <div class="item" [class.active]="item.active">{{ item.name }}</div>
}';
    $dom = HTML_Parser::parse($input, false, null, false, true);
    $this->assertEquals($expected, $dom->render());
  }

  public function testNormalizeWhitespaceEmptyTag()
  {
    $input = '<div   ></div>';
    $expected = '<div></div>';
    $dom = HTML_Parser::parse($input, false, null, false, false);
    $this->assertEquals($expected, $dom->render());
  }

  public function testPreserveWhitespaceEmptyTag()
  {
    $input = '<div   ></div>';
    $dom = HTML_Parser::parse($input, false, null, true, false);
    $this->assertEquals($input, $dom->render());
  }

  public function testNormalizeWhitespaceNoAttributes()
  {
    $input = '<div
></div>';
    $expected = '<div></div>';
    $dom = HTML_Parser::parse($input, false, null, false, false);
    $this->assertEquals($expected, $dom->render());
  }

  public function testPreserveWhitespaceNoAttributes()
  {
    $input = '<div
></div>';
    $dom = HTML_Parser::parse($input, false, null, true, false);
    $this->assertEquals($input, $dom->render());
  }

  public function testNormalizeWhitespaceMixedContent()
  {
    $input = '<div
  class="outer"
><span
    class="inner"
  >text</span></div>';
    $expected = '<div class="outer"><span class="inner">text</span></div>';
    $dom = HTML_Parser::parse($input, false, null, false, false);
    $this->assertEquals($expected, $dom->render());
  }
}
