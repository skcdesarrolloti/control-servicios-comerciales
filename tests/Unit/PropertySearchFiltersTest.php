<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SCM\Commercial\PropertySearchFilters;
use SCM\Views\CommercialDashboardView;

final class PropertySearchFiltersTest extends TestCase
{
  public function testCleanNormalizesRangesMinimumsAndYesNo(): void
  {
    $clean = PropertySearchFilters::clean([
      'precio_min' => '$ 1.500.000', 'precio_max' => 'abc', 'area_min' => '80', 'habitaciones' => '3', 'banos' => '-2',
      'amoblado' => 'sí', 'acepta_permuta' => 'NO', 'deposito' => 'quizás', 'tipo_permuta' => ' Vehiculos ', 'edad_max' => '10',
    ]);

    self::assertSame('1500000', $clean['precio_min']);
    self::assertSame('', $clean['precio_max']);
    self::assertSame('80', $clean['area_min']);
    self::assertSame('3', $clean['habitaciones']);
    self::assertSame('', $clean['banos']);
    self::assertSame('Si', $clean['amoblado']);
    self::assertSame('No', $clean['acepta_permuta']);
    self::assertSame('', $clean['deposito']);
    self::assertSame('Vehiculos', $clean['tipo_permuta']);
    self::assertSame('10', $clean['edad_max']);
    self::assertSame(['precio_min', 'precio_max', 'area_min', 'area_max', 'habitaciones', 'banos', 'parqueaderos', 'edad_max', 'estrato', 'tipo_permuta', 'amoblado', 'acepta_permuta', 'deposito', 'vigilancia', 'negociable'], array_keys($clean));
  }

  public function testNoFiltersMeansNoConditions(): void
  {
    self::assertSame([[], []], PropertySearchFilters::sql(PropertySearchFilters::clean([])));
    self::assertSame(0, PropertySearchFilters::activeCount(PropertySearchFilters::clean([])));
  }

  public function testPriceUsesTheBusinessColumnOrEither(): void
  {
    $filters = PropertySearchFilters::clean(['precio_min' => '1000000', 'precio_max' => '3000000']);

    [$rent, $rentArgs] = PropertySearchFilters::sql($filters, 'Arriendo');
    self::assertCount(1, $rent);
    self::assertStringContainsString('precio_arriendo', $rent[0]);
    self::assertStringNotContainsString('precio_venta', $rent[0]);
    self::assertSame([], $rentArgs);
    self::assertStringContainsString('>= 1000000', $rent[0]);
    self::assertStringContainsString('<= 3000000', $rent[0]);

    [$any, $anyArgs] = PropertySearchFilters::sql($filters);
    self::assertStringContainsString('precio_arriendo', $any[0]);
    self::assertStringContainsString('precio_venta', $any[0]);
    self::assertStringContainsString(' OR ', $any[0]);
    self::assertSame([], $anyArgs);

    [$withoutPrice] = PropertySearchFilters::sql($filters, '', false);
    self::assertSame([], $withoutPrice);
  }

  public function testFeatureConditionsAndArguments(): void
  {
    $filters = PropertySearchFilters::clean([
      'area_min' => '100', 'parqueaderos' => '2', 'estrato' => '5', 'amoblado' => 'Si', 'acepta_permuta' => 'No', 'tipo_permuta' => 'Inmueble', 'edad_max' => '5',
    ]);
    [$where, $args] = PropertySearchFilters::sql($filters);
    $sql = implode(' AND ', $where);

    self::assertCount(7, $where);
    self::assertSame(7, PropertySearchFilters::activeCount($filters));
    self::assertStringContainsString('area_construida', $sql);
    self::assertStringContainsString('area_privada', $sql);
    self::assertStringContainsString('area_terreno', $sql);
    self::assertStringContainsString('i.`parqueaderos`', $sql);
    self::assertStringContainsString('TRIM(i.`estrato`) = ?', $sql);
    self::assertStringContainsString("i.`amoblado` LIKE '%\"Si\"%'", $sql);
    self::assertStringContainsString("i.`acepta_permuta` NOT LIKE '%\"Si\"%'", $sql);
    self::assertStringContainsString('i.`tipo_permuta` LIKE ?', $sql);
    self::assertStringContainsString('>= 100', $sql);
    self::assertStringContainsString(' END) >= 2', $sql);
    self::assertStringContainsString('<= 5)', $sql);
    self::assertSame(['5', '%"Inmueble"%', 'Inmueble'], $args);
  }

  public function testSwapFiltersOnlyApplyToSales(): void
  {
    self::assertTrue(PropertySearchFilters::allowsSwap('Venta'));
    self::assertTrue(PropertySearchFilters::allowsSwap('Arriendo/Venta'));
    self::assertFalse(PropertySearchFilters::allowsSwap('Arriendo', 'Venta'));
    self::assertFalse(PropertySearchFilters::allowsSwap(''));
    self::assertTrue(PropertySearchFilters::allowsSwap('', 'Venta'));
    self::assertFalse(PropertySearchFilters::allowsSwap('', 'Arriendo'));

    $filters = PropertySearchFilters::clean(['acepta_permuta' => 'Si', 'tipo_permuta' => 'Inmueble', 'amoblado' => 'Si']);
    $dropped = PropertySearchFilters::withoutSwapUnless(false, $filters);
    self::assertSame('', $dropped['acepta_permuta']);
    self::assertSame('', $dropped['tipo_permuta']);
    self::assertSame('Si', $dropped['amoblado']);
    self::assertSame($filters, PropertySearchFilters::withoutSwapUnless(true, $filters));
  }

  public function testPropertiesViewShowsTheFeatureFiltersAndSwapOnlyForSales(): void
  {
    $options = ['estratos' => ['3', '4'], 'tipos_permuta' => ['Inmueble', 'Vehiculos']];
    $html = CommercialDashboardView::renderPropertyFeatureFilters(
      PropertySearchFilters::clean(['amoblado' => 'Si', 'precio_min' => '2000000']) + ['tipo_negocio' => 'Venta'],
      $options
    );

    self::assertStringContainsString('data-commercial-property-more-filters', $html);
    self::assertStringNotContainsString('<details', $html);
    foreach (['precio_min', 'precio_max', 'area_min', 'area_max', 'habitaciones', 'banos', 'parqueaderos', 'estrato', 'edad_max', 'amoblado', 'acepta_permuta', 'tipo_permuta', 'deposito', 'vigilancia', 'negociable'] as $name) {
      self::assertStringContainsString('name="' . $name . '"', $html);
    }
    self::assertStringContainsString('value="2.000.000"', $html);
    self::assertStringContainsString('<option value="Si" selected>Sí</option>', $html);
    self::assertStringContainsString('<option value="Vehiculos">Vehiculos</option>', $html);

    foreach (['', 'Arriendo'] as $business) {
      $rent = CommercialDashboardView::renderPropertyFeatureFilters(['tipo_negocio' => $business, 'acepta_permuta' => 'Si'], $options);
      self::assertStringContainsString('name="amoblado"', $rent);
      self::assertStringNotContainsString('name="acepta_permuta"', $rent);
      self::assertStringNotContainsString('name="tipo_permuta"', $rent);
    }
    self::assertStringContainsString('name="tipo_permuta"', CommercialDashboardView::renderPropertyFeatureFilters(['tipo_negocio' => 'Arriendo/Venta'], $options));
  }
}
