import 'package:d2k_mobile/app.dart';
import 'package:d2k_mobile/core/l10n/app_strings.dart';
import 'package:d2k_mobile/data/remote_catalog_source.dart';
import 'package:d2k_mobile/data/remote_shop_source.dart';
import 'package:d2k_mobile/domain/models/commerce.dart';
import 'package:d2k_mobile/domain/models/product.dart';
import 'package:d2k_mobile/domain/repositories/catalog_repository.dart';
import 'package:d2k_mobile/screens/account/addresses_screen.dart';
import 'package:d2k_mobile/screens/product/product_detail_screen.dart';
import 'package:d2k_mobile/screens/shell/app_shell.dart';
import 'package:d2k_mobile/state/cart_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/fake_backend.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('screens', widgetTests);

  group('product options', () {
    test('the detail payload carries options in the backend order', () async {
      final source = RemoteCatalogSource(FakeBackend().client());
      final product = await source.product('3091');

      expect(product.options.map((o) => o.name), ['Shoe Size (EU)', 'Color']);
      expect(product.options.first.values, ['40', '41', '42']);
      expect(product.hasOptions, isTrue);
      // Options are not repeated as specifications.
      expect(product.specifications.keys, ['Material']);
    });

    test('a product without options has none', () async {
      final source = RemoteCatalogSource(FakeBackend().client());
      final product = await source.product('3089');
      expect(product.options, isEmpty);
      expect(product.hasOptions, isFalse);
    });

    test('chosen options tolerate null, [] and {}', () {
      expect(parseChosenOptions(null), isEmpty);
      expect(parseChosenOptions(const []), isEmpty);
      expect(parseChosenOptions(const {}), isEmpty);
      expect(parseChosenOptions(const {'Size': 'M'}), {'Size': 'M'});
      expect(
        describeChosenOptions(const {'Size': 'M', 'Color': 'Red'}),
        'Size: M · Color: Red',
      );
    });
  });

  group('cart lines with options', () {
    late CatalogRepository catalog;
    late CartController cart;
    late Product sneakers;

    setUp(() async {
      catalog = CatalogRepository(RemoteCatalogSource(FakeBackend().client()));
      cart = CartController(catalog);
      sneakers = await catalog.product('3091');
    });

    test('different options are separate lines, same options merge', () {
      cart.add(sneakers, options: {'Shoe Size (EU)': '42', 'Color': 'Black'});
      cart.add(sneakers, options: {'Color': 'Black', 'Shoe Size (EU)': '42'});
      cart.add(sneakers, options: {'Shoe Size (EU)': '41', 'Color': 'Black'});

      expect(cart.items, hasLength(2));
      expect(cart.itemCount, 3);
      expect(cart.totalQuantityOf(sneakers), 3);
      expect(
        cart.quantityOf(
          sneakers,
          options: {'Shoe Size (EU)': '42', 'Color': 'Black'},
        ),
        2,
      );
      expect(
        cart.items.first.optionsLabel,
        'Shoe Size (EU): 42 · Color: Black',
      );
    });

    test('a line without a required choice is flagged before checkout', () {
      cart.add(sneakers);
      final missing = cart.firstMissingChoice();
      expect(missing, isNotNull);
      expect(missing!.option.name, 'Shoe Size (EU)');

      // Choosing on the product page supersedes the incomplete line.
      cart.add(sneakers, options: {'Shoe Size (EU)': '40', 'Color': 'White'});
      expect(cart.items, hasLength(1));
      expect(cart.firstMissingChoice(), isNull);
    });

    test('carts saved before options existed still load', () async {
      SharedPreferences.setMockInitialValues({
        'd2k.cart':
            '[{"id":"3091","qty":2,"variant":null},'
            '{"id":"3091","qty":1,"variant":null,'
            '"options":{"Shoe Size (EU)":"41","Color":"White"}}]',
      });
      final restored = CartController(catalog);
      await restored.load();
      expect(restored.items, hasLength(2));
      expect(restored.items.first.options, isEmpty);
      expect(restored.items.last.options['Color'], 'White');
    });

    test('checkout sends each line with its options', () async {
      final backend = FakeBackend();
      cart.add(sneakers, options: {'Shoe Size (EU)': '42', 'Color': 'Black'});
      await cart.checkout(
        shop: RemoteShopSource(backend.client()),
        address: 'Hall 5, Block B · UDSM Mlimani',
        phone: '+255712000222',
        addressId: '9',
      );

      final body = backend.sentBodies['/shop/orders']!;
      final line = (body['items'] as List).single as Map;
      expect(line['product_id'], 3091);
      expect(line['options'], {'Shoe Size (EU)': '42', 'Color': 'Black'});
      expect(body['address_id'], 9);
      expect(cart.isEmpty, isTrue);
    });
  });

  group('orders', () {
    test('order lines carry the chosen options', () async {
      final shop = RemoteShopSource(FakeBackend().client());
      final order = (await shop.orders()).first;
      expect(order.items.first.options, isEmpty);
      expect(
        order.items.last.optionsLabel,
        'Shoe Size (EU): 42 · Color: Black',
      );
    });
  });

  group('campus delivery', () {
    test('campuses come from the API', () async {
      final backend = FakeBackend();
      final campuses = await RemoteShopSource(backend.client()).campuses();
      expect(backend.requestedPaths, contains('/shop/campuses'));
      expect(campuses, hasLength(2));
      expect(campuses.first.displayName, 'UDSM Mlimani');
      expect(campuses.first.deliveryFee, isNull);
      expect(campuses.last.displayName, 'Ardhi University');
      expect(campuses.last.deliveryFee, 2000);
      expect(campuses.first.searchIndex, contains('ubungo'));
    });

    test('a campus address is saved with campus_id and details', () async {
      final backend = FakeBackend();
      await RemoteShopSource(backend.client()).createAddress(
        const AddressDraft(
          fullName: 'Asha Juma',
          phone: '+255712000222',
          region: 'Dar es Salaam',
          city: 'Dar es Salaam',
          details: 'Hall 5, Block B, Room 12',
          campusId: '1',
        ),
      );
      final body = backend.sentBodies['/shop/addresses']!;
      expect(body['campus_id'], 1);
      expect(body['details'], 'Hall 5, Block B, Room 12');
    });

    test('an ordinary address clears the campus', () {
      const draft = AddressDraft(
        fullName: 'A',
        phone: '1',
        city: 'Dar es Salaam',
      );
      expect(draft.toJson().containsKey('campus_id'), isTrue);
      expect(draft.toJson()['campus_id'], isNull);
    });

    test('a campus address summary names the campus', () {
      const address = Address(
        id: '9',
        label: 'Ubungo',
        line1: 'University of Dar es Salaam (UDSM) – Mlimani',
        district: 'Ubungo',
        city: 'Dar es Salaam',
        country: 'Dar es Salaam',
        details: 'Hall 5, Block B',
        campusId: '1',
        campusName: 'UDSM Mlimani',
      );
      expect(address.isCampus, isTrue);
      expect(address.summary, 'Hall 5, Block B · UDSM Mlimani');
    });
  });
}

// ------------------------------------------------------------------ widgets

Future<BuildContext> _pumpApp(WidgetTester tester) async {
  SharedPreferences.setMockInitialValues({
    'd2k.language': 'en',
    'd2k.onboarded': true,
  });
  tester.view.physicalSize = const Size(1179, 2556);
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(D2KApp(api: FakeBackend().client()));
  await tester.pump(const Duration(seconds: 2));
  await tester.pump(const Duration(seconds: 1));
  return tester.element(find.byType(AppShell));
}

void widgetTests() {
  const strings = AppStrings(AppLanguage.english);

  testWidgets('Add to cart asks for every option before adding', (
    tester,
  ) async {
    final context = await _pumpApp(tester);
    const summary = Product(
      id: '3091',
      title: 'Canvas Sneakers',
      brand: '',
      categoryId: '3',
      subcategory: '',
      priceBase: 30000,
      images: [],
      stock: 20,
    );
    ProductDetailScreen.open(context, summary);
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump(const Duration(seconds: 1));

    expect(find.text('Shoe Size (EU)'), findsOneWidget);
    expect(find.text('Color'), findsOneWidget);

    final cart = Provider.of<CartController>(context, listen: false);
    await tester.tap(find.text(strings.addToCart));
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.text(strings.chooseOption('Shoe Size (EU)')), findsOneWidget);
    expect(cart.isEmpty, isTrue);

    // The missing card was scrolled into view; pick a value in each.
    await tester.ensureVisible(find.text('42'));
    await tester.pump();
    await tester.tap(find.text('42'));
    await tester.pump();
    await tester.ensureVisible(find.text('Black'));
    await tester.pump();
    await tester.tap(find.text('Black'));
    await tester.pump(const Duration(seconds: 3));
    await tester.tap(find.text(strings.addToCart));
    await tester.pump(const Duration(seconds: 3));

    expect(cart.items, hasLength(1));
    expect(cart.items.single.options, {
      'Shoe Size (EU)': '42',
      'Color': 'Black',
    });
    expect(tester.takeException(), isNull);
  });

  testWidgets('campus picker lists and searches campuses', (tester) async {
    final context = await _pumpApp(tester);
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => const AddressEditorScreen(campus: true),
      ),
    );
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump(const Duration(seconds: 1));

    expect(find.text('${strings.hostelBlockRoom} *'), findsOneWidget);
    await tester.tap(find.text('${strings.chooseCampus} *'));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump(const Duration(seconds: 1));

    expect(find.text('Ardhi University'), findsOneWidget);
    expect(find.textContaining('UDSM'), findsWidgets);

    await tester.enterText(find.byType(TextField).last, 'ardhi');
    await tester.pump();
    expect(
      find.text('University of Dar es Salaam (UDSM) – Mlimani'),
      findsNothing,
    );

    await tester.tap(find.text('Ardhi University'));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pump(const Duration(seconds: 1));
    // The chosen campus now fills the field.
    expect(find.text('Ardhi University'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
