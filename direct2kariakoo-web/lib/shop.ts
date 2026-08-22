import api from "./api";
import type {
  Address,
  AddressInput,
  CheckoutPreview,
  ChatMessage,
  ChatParticipant,
  ChatProductContext,
  ChatThread,
  VendorSummary,
  Category,
  HomeFeed,
  Order,
  ProductCard,
  ProductDetail,
  ProductListing,
} from "./types";

/**
 * Typed client for the public storefront API.
 *
 * Every network call the storefront makes goes through here, so request
 * shapes, query-string building and error handling live in one place rather
 * than being re-invented in each page component.
 */

export interface ProductQuery {
  category_id?: number;
  subcategory_id?: number;
  vendor_id?: number;
  q?: string;
  min_price?: number;
  max_price?: number;
  in_stock?: boolean;
  on_sale?: boolean;
  rating?: number;
  sort?: "newest" | "price_asc" | "price_desc" | "rating" | "discount" | "relevance";
  page?: number;
  per_page?: number;
}

function toParams(query: ProductQuery): Record<string, string> {
  const params: Record<string, string> = {};

  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === "") continue;
    // Laravel's boolean validation accepts 1/0 but not "true"/"false" strings.
    params[key] = typeof value === "boolean" ? (value ? "1" : "0") : String(value);
  }

  return params;
}

/**
 * Shared across every component that asks for the category nav during one page
 * session. Cleared on a full page load, which is the only time the list could
 * matter enough to re-read.
 */
let categoryCache: Promise<Category[]> | null = null;

export const shop = {
  async home(): Promise<HomeFeed> {
    const { data } = await api.get<HomeFeed>("/shop/home");
    return data;
  },

  async vendors(): Promise<VendorSummary[]> {
    const { data } = await api.get<{ vendors: VendorSummary[] }>("/shop/vendors");
    return data.vendors;
  },

  /**
   * The category nav, fetched at most once per page session.
   *
   * Every page mounts SiteChrome, so this used to be a fresh request on every
   * navigation — and against the live API that is ~6 seconds each time, for a
   * list that changes when an administrator edits it and not otherwise.
   *
   * The in-flight promise is shared too, so the handful of components that ask
   * during the same paint make one request between them rather than four.
   *
   * Deliberately memory-only and deliberately not applied to anything else:
   * stock, carts, orders and payment details must never be served from a
   * stale copy, and none of them come through here.
   */
  async categories(): Promise<Category[]> {
    if (categoryCache) return categoryCache;

    categoryCache = api
      .get<{ categories: Category[] }>("/shop/categories")
      .then(({ data }) => data.categories)
      .catch((error) => {
        // A failure must not be cached, or the nav stays empty for the rest
        // of the session over one dropped request.
        categoryCache = null;
        throw error;
      });

    return categoryCache;
  },

  async category(id: number) {
    const { data } = await api.get(`/shop/categories/${id}`);
    return data as {
      category: { id: number; name: string; image: string | null };
      subcategories: { id: number; name: string; image: string | null; product_count: number }[];
      shelves: { id: number; title: string; products: ProductCard[] }[];
    };
  },

  async products(query: ProductQuery = {}): Promise<ProductListing> {
    const { data } = await api.get<ProductListing>("/shop/products", {
      params: toParams(query),
    });
    return data;
  },

  async product(id: number) {
    const { data } = await api.get(`/shop/products/${id}`);
    return data as {
      product: ProductDetail;
      related: ProductCard[];
      from_vendor: ProductCard[];
    };
  },

  async suggest(term: string) {
    const { data } = await api.get("/shop/products/suggest", { params: { q: term } });
    return data as {
      products: { id: number; name: string; price: number }[];
      categories: { id: number; name: string }[];
    };
  },

  /* ---- authenticated ---- */

  async wishlist() {
    const { data } = await api.get("/shop/wishlist");
    return data as { products: ProductCard[]; ids: number[] };
  },

  async addToWishlist(productId: number) {
    await api.post("/shop/wishlist", { product_id: productId });
  },

  async removeFromWishlist(productId: number) {
    await api.delete(`/shop/wishlist/${productId}`);
  },

  async syncWishlist(productIds: number[]) {
    const { data } = await api.post("/shop/wishlist/sync", { product_ids: productIds });
    return data as { products: ProductCard[]; ids: number[] };
  },

  async orders(): Promise<Order[]> {
    const { data } = await api.get<{ orders: Order[] }>("/shop/orders");
    return data.orders;
  },

  async order(reference: string): Promise<Order> {
    const { data } = await api.get<{ order: Order }>(`/shop/orders/${reference}`);
    return data.order;
  },

  /**
   * Price the basket and find out who is being paid.
   *
   * Read-only and safe to call whenever the basket or the delivery pin
   * changes. The server owns the arithmetic — the storefront no longer keeps
   * its own copy of the delivery charge to drift out of sync.
   */
  async checkoutPreview(payload: {
    items: { product_id: number; quantity: number }[];
    delivery_latitude?: number | null;
    delivery_longitude?: number | null;
  }): Promise<CheckoutPreview> {
    const { data } = await api.post<CheckoutPreview>("/shop/checkout/preview", payload);
    return data;
  },

  async placeOrder(payload: {
    items: { product_id: number; quantity: number }[];
    delivery_address: string;
    customer_phone: string;
    payment_method: "lipa_namba" | "mobile_money";
    payment_provider?: string;
    /** The destination pin, when one has been dropped. Delivery is priced from it. */
    delivery_latitude?: number | null;
    delivery_longitude?: number | null;
  }) {
    const { data } = await api.post("/shop/orders", payload);
    return data as { message: string; reference: string; order: Order };
  },

  async cancelOrder(reference: string) {
    await api.post(`/shop/orders/${reference}/cancel`);
  },

  /* ---------------- shopper ↔ seller messaging ---------------- */

  async chatThreads(): Promise<ChatThread[]> {
    const { data } = await api.get<{ threads: ChatThread[] }>("/shop/chat/threads");
    return data.threads;
  },

  async chatThread(userId: number) {
    const { data } = await api.get<{
      participant: ChatParticipant;
      product: ChatProductContext | null;
      messages: ChatMessage[];
    }>(`/shop/chat/${userId}`);
    return data;
  },

  async sendChat(payload: {
    vendor_id?: number;
    user_id?: number;
    product_id?: number;
    message: string;
  }): Promise<ChatMessage> {
    const { data } = await api.post<{ message: ChatMessage }>("/shop/chat", payload);
    return data.message;
  },

  /* ---------------- delivery address book ---------------- */

  async addresses(): Promise<Address[]> {
    const { data } = await api.get<{ addresses: Address[] }>("/shop/addresses");
    return data.addresses;
  },

  async createAddress(payload: AddressInput): Promise<Address[]> {
    const { data } = await api.post<{ addresses: Address[] }>("/shop/addresses", payload);
    return data.addresses;
  },

  async updateAddress(id: number, payload: AddressInput): Promise<Address[]> {
    const { data } = await api.put<{ addresses: Address[] }>(`/shop/addresses/${id}`, payload);
    return data.addresses;
  },

  async deleteAddress(id: number): Promise<Address[]> {
    const { data } = await api.delete<{ addresses: Address[] }>(`/shop/addresses/${id}`);
    return data.addresses;
  },

  async setDefaultAddress(id: number): Promise<Address[]> {
    const { data } = await api.post<{ addresses: Address[] }>(`/shop/addresses/${id}/default`);
    return data.addresses;
  },
};

export default shop;
