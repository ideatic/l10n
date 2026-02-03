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

  public function testAngularForWithNestedIfElse()
  {
    $input = '@for (network of preparedNetworks(); track network) {
      @if (network.icon.startsWith(\'<svg\')) {
        <a target="_blank" [href]="network.url" [title]="network.name" [innerHTML]="network.icon | safe: \'html\'" (click)="trackClick(network.url)"> </a>
      } @else {
        <a target="_blank" [href]="network.url" [title]="network.name" (click)="trackClick(network.url)">
          <i [icon]="network.icon"></i>
        </a>
      }
    }';

    $dom = HTML_Parser::parse($input, true, null, true);
    $this->assertEquals($input, $dom->render());
  }
}
