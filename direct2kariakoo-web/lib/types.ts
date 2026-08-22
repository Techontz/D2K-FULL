/** Shapes returned by the Laravel storefront API (`/api/shop/*`). */

export interface Price {
  currency: string;
  current: number;
  was: number | null;
  discount_percent: number | null;
}

export interface Rating {
  average: number;
  count: number;
}

export interface Ref {
  id: number;
  name: string;
}

export interface ProductCard {
  id: number;
  name: string;
  image: string | null;
  images: string[];
  price: Price;
  rating: Rating;
  stock: number;
  in_stock: boolean;
  category?: Ref;
  subcategory?: Ref;
  vendor?: Ref;
  badges: {
    low_stock: boolean;
    out_of_stock: boolean;
    discounted: boolean;
  };
}

export interface Specification {
  label: string;
  value: string;
}

export interface Review {
  id: number;
  author: string;
  rating: number;
  comment: string | null;
  date: string | null;
}

/** Seller block on the product page, with contact details already normalised. */
export interface ProductVendor {
  id: number;
  name: string;
  logo: string | null;
  /** E.164, or null when the stored number is not dialable. */
  phone: string | null;
  phone_display: string | null;
  /** Ready-made wa.me link, or null when the number cannot take WhatsApp. */
  whatsapp: string | null;
  location: string | null;
  website: string | null;
  about: string | null;
  is_approved: boolean;
  /** Granted only by an administrator; drives the verified checkmark. */
  is_verified: boolean;
  member_since: string | null;
  /** The seller's account, used to open a chat thread. */
  user_id: number | null;
}

export interface ChatProductContext {
  id: number;
  name: string;
  image: string | null;
}

export interface ChatMessage {
  id: number;
  body: string;
  mine: boolean;
  read: boolean;
  sent_at: string | null;
  product: ChatProductContext | null;
}

export interface ChatParticipant {
  user_id: number;
  name: string;
  is_vendor: boolean;
  vendor_id: number | null;
  avatar: string | null;
}

export interface ChatThread extends ChatParticipant {
  last_message: string;
  last_at: string | null;
  unread: number;
  product: ChatProductContext | null;
}

export interface ProductDetail {
  id: number;
  name: string;
  /** One-line summary, separate from the long-form description. */
  short_description: string | null;
  description: string | null;
  image: string | null;
  images: string[];
  price: Price;
  stock: number;
  in_stock: boolean;
  category: Ref | null;
  subcategory: Ref | null;
  vendor: ProductVendor | null;
  specifications: Specification[];
  rating: Rating & {
    distribution: { star: number; count: number; percent: number }[];
  };
  reviews: Review[];
}

export interface Category {
  id: number;
  name: string;
  icon: string | null;
  image: string | null;
  product_count: number;
  subcategories: {
    id: number;
    name: string;
    icon: string | null;
    image: string | null;
  }[];
}

export interface Banner {
  id: number;
  title: string | null;
  alt: string | null;
  link: string | null;
  image: string | null;
}

export interface Shelf {
  id: number;
  title: string;
  products: ProductCard[];
}

/** A banner as placed on the homepage by an administrator. */
export interface HeroBanner {
  id: number;
  title: string | null;
  subtitle: string | null;
  alt: string | null;
  link: string | null;
  cta_label: string | null;
  theme: string | null;
  image: string | null;
  /** Falls back to `image` server-side when no phone crop is uploaded. */
  mobile_image: string | null;
}

/** One tile in a "shop the category" strip. */
export interface CollectionTile {
  id: number;
  category_id: number;
  name: string;
  product_count: number;
  image: string | null;
}

export interface CategoryCollection {
  id: number;
  title: string;
  tiles: CollectionTile[];
}

export interface HomeFeed {
  /** Kept for the Flutter app; the website reads `hero`. */
  banners: Banner[];
  hero: HeroBanner[];
  hero_side: HeroBanner | null;
  promos: HeroBanner[];
  categories: Omit<Category, "subcategories">[];
  collections: CategoryCollection[];
  shelves: Shelf[];
  deals: ProductCard[];
}

export interface VendorSummary {
  id: number;
  name: string;
  logo: string | null;
  product_count: number;
  is_verified: boolean;
  member_since: string | null;
}

export interface ListingMeta {
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
  has_more: boolean;
}

export interface ListingFilters {
  price: { min: number; max: number };
  subcategories: { id: number; name: string; count: number }[];
}

export interface ProductListing {
  products: ProductCard[];
  meta: ListingMeta;
  filters: ListingFilters;
}

export interface CartLine {
  product: ProductCard;
  quantity: number;
}

export interface OrderItem {
  id: number;
  product: { id: number; name: string; image: string | null } | null;
  vendor: string | null;
  quantity: number;
  price: number;
  total: number;
  status: string;
}

/**
 * Who the customer pays, and how much — one recipient for the whole order.
 *
 * Read off the order rather than off current settings: the number is
 * snapshotted when the order is placed, so an administrator changing the
 * marketplace till later does not rewrite what a past customer was told.
 */
export interface OrderPayment {
  /** Null on orders placed before central payment existed. */
  lipa_namba: string | null;
  lipa_namba_display: string | null;
  account_name: string | null;
  /** One payment covers the whole order, however many sellers it spans. */
  amount: number;
}

/** One seller's share of an order. Presentation only — never payment routing. */
export interface VendorBreakdown {
  vendor_id: number;
  vendor_name: string;
  subtotal: number;
  item_count: number;
}

export interface Order {
  reference: string;
  status: string;
  placed_at: string | null;
  item_count: number;
  subtotal: number;
  /** Null while delivery is still to be priced — not the same as free. */
  delivery_fee: number | null;
  delivery_status: "pending" | "quoted";
  total: number;
  currency: string;
  payment_method: string | null;
  delivery_address: string | null;
  customer_phone: string | null;
  payment: OrderPayment;
  /** `pending_payment` until D2K confirms the money arrived. */
  payment_status: string;
  vendor_breakdown: VendorBreakdown[];
  items: OrderItem[];
}

/** One seller's share of a basket. Presentation only — payment is central. */
export interface CheckoutVendorGroup {
  vendor_id: number | null;
  vendor_name: string;
  subtotal: number;
  items: { product_id: number; name: string; quantity: number; price: number; total: number }[];
}

/**
 * The basket as the server prices it.
 *
 * The storefront used to add its own copy of the delivery charge, which is how
 * a customer sees one total and is charged another. This is now the only
 * arithmetic on the page.
 */
export interface CheckoutPreview {
  currency: string;
  subtotal: number;
  delivery: {
    status: "pending" | "quoted";
    amount: number | null;
    distance_km: number | null;
    reason: string | null;
  };
  total: number;

  /**
   * Direct2Kariakoo's own till, and the single amount to send to it. One
   * payment covers the basket however many shops it came from.
   */
  payment: {
    lipa_namba: string | null;
    lipa_namba_display: string | null;
    account_name: string;
    is_payable: boolean;
    amount: number;
  };

  /** Which shop each item comes from. Presentation, not payment routing. */
  vendor_groups: CheckoutVendorGroup[];

  can_place_order: boolean;
  /** True when the marketplace itself has no usable till configured. */
  payment_unavailable: boolean;
  missing_products: number[];
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  role: "user" | "vendor" | "admin";
  vendor?: {
    id: number;
    business_name: string;
    is_approved: boolean;
    logo?: string | null;
  } | null;
}

/** A saved delivery address. Mirrors Api\Shop\AddressController::present(). */
export interface Address {
  id: number;
  full_name: string;
  phone: string;
  region: string;
  city: string;
  district: string | null;
  street: string | null;
  details: string | null;
  latitude: number | null;
  longitude: number | null;
  is_default: boolean;
  /** Courier-readable single line, composed by the backend. */
  formatted: string;
}

/** The writable subset of an address. */
export type AddressInput = Omit<Address, "id" | "formatted">;
