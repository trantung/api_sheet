# Cart Token Flow - Chi tiết luồng từ Frontend đến Backend

## Tổng quan

Hệ thống sử dụng `cart_token` (UUID) để nhận diện giỏ hàng của guest users. `cart_token` được tự động tạo và quản lý bởi backend, frontend chỉ cần lưu và gửi lại trong các request tiếp theo.

---

## 1. Luồng tổng quan

```
Frontend (React)                    Backend (Laravel)
     |                                    |
     | 1. POST /api/cart                  |
     |    (không có cart_token)           |
     |---------------------------------->|
     |                                    | 2. CartTokenMiddleware
     |                                    |    - Kiểm tra cart_token
     |                                    |    - Nếu chưa có → tạo UUID mới
     |                                    |    - Set cookie cart_token
     |                                    |
     | 3. Response với cart_token         |
     |    (trong cookie + response)      |
     |<----------------------------------|
     |                                    |
     | 4. Lưu cart_token vào state       |
     |                                    |
     | 5. Các request tiếp theo           |
     |    (gửi cart_token)               |
     |---------------------------------->|
     |                                    | 6. Sử dụng cart_token để
     |                                    |    lấy/update cart trong Redis
     |                                    |
```

---

## 2. Chi tiết từng bước

### Bước 1: Frontend lần đầu load website

**Frontend Code (React):**
```javascript
// Component mount lần đầu
useEffect(() => {
  // Gọi API để lấy giỏ hàng
  fetchCart();
}, []);

const fetchCart = async () => {
  try {
    const response = await fetch('https://api.sheet.microgem.io.vn/api/cart', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Origin': window.location.origin,
        // Không cần gửi cart_token trong body (có thể gửi {} hoặc không gửi)
        // Cookie sẽ tự động được gửi nếu có
      },
      credentials: 'include', // Quan trọng: gửi cookie
      body: JSON.stringify({}), // Body có thể rỗng hoặc có cart_token
    });
    
    const data = await response.json();
    
    // Lưu cart_token vào state/localStorage
    const cartToken = getCartTokenFromCookie(); // Đọc từ cookie sau khi response
    if (cartToken) {
      setCartToken(cartToken);
      localStorage.setItem('cart_token', cartToken); // Backup
    }
    setCart(data.data);
  } catch (error) {
    console.error('Error fetching cart:', error);
  }
};
```

**Lưu ý:**
- POST request có body, có thể gửi `cart_token` trong body (optional)
- Cookie sẽ tự động được gửi nếu có (với `credentials: 'include'`)
- Nếu chưa có cookie, middleware sẽ tự động tạo và set trong response
- Body có thể rỗng `{}` hoặc không gửi, middleware vẫn hoạt động bình thường

**Request gửi đi (lần đầu - chưa có cookie):**
```http
POST /api/cart HTTP/1.1
Host: api.sheet.microgem.io.vn
Origin: https://testshop3.microgem.io.vn
Accept: application/json
Content-Type: application/json
Cookie: (chưa có cart_token)

{}
```

**Request gửi đi (có cookie):**
```http
POST /api/cart HTTP/1.1
Host: api.sheet.microgem.io.vn
Origin: https://testshop3.microgem.io.vn
Accept: application/json
Content-Type: application/json
Cookie: cart_token=550e8400-e29b-41d4-a716-446655440000

{}
```

**Request gửi đi (với cart_token trong body):**
```http
POST /api/cart HTTP/1.1
Host: api.sheet.microgem.io.vn
Origin: https://testshop3.microgem.io.vn
Accept: application/json
Content-Type: application/json

{
  "cart_token": "550e8400-e29b-41d4-a716-446655440000"
}
```

---

### Bước 2: Backend xử lý - CartTokenMiddleware

**File:** `app/Http/Middleware/CartTokenMiddleware.php`

```php
public function handle(Request $request, Closure $next): Response
{
    // 1. Lấy cart_token từ 3 nguồn (theo thứ tự ưu tiên):
    $cartToken = $request->cookie('cart_token')      // Từ cookie
        ?? $request->header('X-Cart-Token')          // Từ header
        ?? $request->input('cart_token');             // Từ body
    
    // 2. Kiểm tra và tạo mới nếu chưa có
    if (!$cartToken || !$this->isValidUuid($cartToken)) {
        // Tạo UUID mới
        $cartToken = (string) Str::uuid();
        // Ví dụ: "550e8400-e29b-41d4-a716-446655440000"
    }
    
    // 3. Attach cart_token vào request
    $request->merge(['cart_token' => $cartToken]);
    
    // 4. Xử lý request tiếp theo
    $response = $next($request);
    
    // 5. Set cookie nếu chưa có
    if (!$request->cookie('cart_token')) {
        $response->cookie(
            'cart_token', 
            $cartToken, 
            60 * 24 * 3,  // 3 ngày
            '/',           // Path
            null,          // Domain (null = current domain)
            false,         // Secure (false = http và https)
            false          // HttpOnly (false = JS có thể đọc)
        );
    }
    
    return $response;
}
```

**Giải thích:**
- Middleware chạy **trước** khi request đến Controller
- Kiểm tra cart_token từ 3 nguồn: cookie, header, body
- Nếu chưa có hoặc không hợp lệ → tạo UUID mới
- Set cookie với `httpOnly = false` để frontend có thể đọc được

---

### Bước 3: Backend xử lý - CartController

**File:** `app/Http/Controllers/CartController.php`

```php
public function getCart(Request $request)
{
    // Lấy cart_token từ nhiều nguồn (theo thứ tự ưu tiên):
    // 1. Từ request đã được middleware merge (ưu tiên cao nhất)
    // 2. Từ cookie
    // 3. Từ header
    // 4. Từ body (nếu có)
    $cartToken = $request->get('cart_token') 
        ?? $request->cookie('cart_token')
        ?? $request->header('X-Cart-Token')
        ?? $request->input('cart_token');
    
    $domain = $this->cartService->getDomainFromRequest();
    
    // Lấy giỏ hàng từ Redis
    $result = $this->cartService->getCartWithDetails($cartToken, $domain);
    
    return response()->json([
        'success' => true,
        'status' => 200,
        'data' => $result
    ]);
}
```

**Giải thích:**
- `$request->get('cart_token')`: Lấy từ request đã được middleware merge (hoạt động với cả GET và POST)
- `$request->cookie('cart_token')`: Lấy từ cookie (fallback)
- `$request->header('X-Cart-Token')`: Lấy từ header (fallback)
- `$request->input('cart_token')`: Lấy từ body (hoạt động với POST có body)

---

### Bước 4: Backend xử lý - CartService

**File:** `app/Service/CartService.php`

```php
public function getCart($cartToken)
{
    // Key format: cart:guest:{cart_token}
    $cartKey = 'cart:guest:' . $cartToken;
    
    // Lấy từ Redis
    $cartData = Cache::store('redis')->get($cartKey);
    
    if (!$cartData) {
        // Giỏ hàng trống
        return [
            'items' => [],
            'updated_at' => now()->toIso8601String()
        ];
    }
    
    return is_array($cartData) ? $cartData : json_decode($cartData, true);
}

public function getCartWithDetails($cartToken, $domain)
{
    // 1. Lấy cart từ Redis (chỉ có product_id, variant_id, qty)
    $cart = $this->getCart($cartToken);
    
    // 2. Load product details từ database
    $items = [];
    $subtotal = 0;
    
    foreach ($cart['items'] as $item) {
        // Query database để lấy thông tin sản phẩm
        $product = DB::connection($connectionName)
            ->table('products')
            ->where('id', $item['product_id'])
            ->first();
        
        if (!$product) {
            continue; // Skip invalid products
        }
        
        // Lấy giá hiện tại từ database (không tin cache)
        $price = floatval($product->price);
        $qty = $item['qty'];
        $itemTotal = $price * $qty;
        $subtotal += $itemTotal;
        
        // Lấy thumbnail
        $thumbnail = $this->getProductThumbnail($product);
        
        $items[] = [
            'product_id' => $item['product_id'],
            'variant_id' => $item['variant_id'] ?? null,
            'sku' => $product->sku,
            'name' => $product->name,
            'price' => (string)$price,  // Giá từ database
            'quantity' => $qty,
            'thumbnail' => $thumbnail,
        ];
    }
    
    return [
        'items' => $items,
        'subtotal' => $subtotal,
        'count' => count($items),
        'updated_at' => $cart['updated_at']
    ];
}
```

**Lưu ý quan trọng:**
- Redis chỉ lưu: `product_id`, `variant_id`, `qty`
- **KHÔNG lưu giá** trong Redis
- Giá được lấy từ database mỗi lần get cart
- Đảm bảo giá luôn là giá hiện tại

**Controller lấy cart_token:**
```php
// Lấy từ nhiều nguồn (theo thứ tự ưu tiên)
$cartToken = $request->get('cart_token')      // Từ request đã merge bởi middleware
    ?? $request->cookie('cart_token')          // Từ cookie
    ?? $request->header('X-Cart-Token')        // Từ header
    ?? $request->input('cart_token');          // Từ body (POST có body)
```

---

### Bước 5: Response về Frontend

**Response:**
```json
{
    "success": true,
    "status": 200,
    "data": {
        "items": [
            {
                "product_id": 1,
                "variant_id": null,
                "sku": "789-03",
                "name": "Tungalso1",
                "price": "200.33",
                "quantity": 2,
                "thumbnail": "https://ik.imagekit.io/sheets/Tungalso1.png"
            }
        ],
        "subtotal": 400.66,
        "count": 1,
        "updated_at": "2026-01-24T11:44:16+07:00"
    }
}
```

**HTTP Headers:**
```http
HTTP/1.1 200 OK
Set-Cookie: cart_token=550e8400-e29b-41d4-a716-446655440000; Path=/; Max-Age=259200; HttpOnly=false
Content-Type: application/json
```

**Frontend nhận được:**
- Response data với giỏ hàng
- Cookie `cart_token` được set tự động
- Frontend có thể đọc cookie bằng JavaScript (vì httpOnly=false)

---

### Bước 6: Frontend lưu cart_token

**Frontend Code:**
```javascript
// Cách 1: Đọc từ cookie (khuyến nghị)
const getCartToken = () => {
  const cookies = document.cookie.split(';');
  for (let cookie of cookies) {
    const [name, value] = cookie.trim().split('=');
    if (name === 'cart_token') {
      return value;
    }
  }
  return null;
};

// Cách 2: Lưu vào localStorage (backup)
const saveCartToken = (token) => {
  localStorage.setItem('cart_token', token);
};

// Cách 3: Lưu vào state (React)
const [cartToken, setCartToken] = useState(null);

useEffect(() => {
  const token = getCartToken();
  if (token) {
    setCartToken(token);
    saveCartToken(token); // Backup vào localStorage
  }
}, []);
```

---

### Bước 7: Frontend gửi request tiếp theo

**Ví dụ: Add to cart**

**Frontend Code:**
```javascript
const addToCart = async (productId, variantId, qty) => {
  // Lấy cart_token từ cookie hoặc state
  const cartToken = getCartToken() || localStorage.getItem('cart_token');
  
  try {
    const response = await fetch('https://api.sheet.microgem.io.vn/api/cart/add', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Origin': window.location.origin,
        // Optional: có thể gửi qua header
        // 'X-Cart-Token': cartToken,
      },
      credentials: 'include', // Quan trọng: gửi cookie
      body: JSON.stringify({
        cart_token: cartToken, // Optional: gửi trong body
        product_id: productId,
        variant_id: variantId,
        qty: qty,
      }),
    });
    
    const data = await response.json();
    return data;
  } catch (error) {
    console.error('Error adding to cart:', error);
  }
};
```

**Request gửi đi:**
```http
POST /api/cart/add HTTP/1.1
Host: api.sheet.microgem.io.vn
Origin: https://testshop3.microgem.io.vn
Content-Type: application/json
Cookie: cart_token=550e8400-e29b-41d4-a716-446655440000
Accept: application/json

{
  "cart_token": "550e8400-e29b-41d4-a716-446655440000",
  "product_id": 1,
  "variant_id": null,
  "qty": 2
}
```

**Backend xử lý:**
1. Middleware nhận cart_token từ cookie (ưu tiên cao nhất)
2. Validate cart_token
3. CartService lấy cart từ Redis: `cart:guest:{cart_token}`
4. Thêm sản phẩm vào cart
5. Lưu lại vào Redis với TTL 3 ngày
6. Trả về cart đã cập nhật

---

## 3. Data Structure

### Redis Storage (cart:guest:{cart_token})

```json
{
  "items": [
    {
      "product_id": 1,
      "variant_id": null,
      "qty": 2
    },
    {
      "product_id": 3,
      "variant_id": 5,
      "qty": 1
    }
  ],
  "updated_at": "2026-01-24T11:44:16+07:00"
}
```

**Lưu ý:**
- Chỉ lưu ID và số lượng
- Không lưu giá, tên, hình ảnh
- TTL: 3 ngày (tự động reset khi update)

### API Response

```json
{
  "items": [
    {
      "product_id": 1,
      "variant_id": null,
      "sku": "789-03",
      "name": "Tungalso1",
      "price": "200.33",        // Từ database
      "quantity": 2,
      "thumbnail": "https://..." // Từ database
    }
  ],
  "subtotal": 400.66,           // Tính từ database
  "count": 1,
  "updated_at": "2026-01-24T11:44:16+07:00"
}
```

---

## 4. Các nguồn lấy cart_token (theo thứ tự ưu tiên)

### Middleware (CartTokenMiddleware) - Lấy cart_token từ:

1. **Cookie** (Ưu tiên cao nhất)
```http
Cookie: cart_token=550e8400-e29b-41d4-a716-446655440000
```

2. **Header**
```http
X-Cart-Token: 550e8400-e29b-41d4-a716-446655440000
```

3. **Request Body**
```json
{
  "cart_token": "550e8400-e29b-41d4-a716-446655440000",
  "product_id": 1,
  "qty": 2
}
```

Sau đó middleware merge vào request: `$request->merge(['cart_token' => $cartToken])`

### Controller (CartController) - Lấy cart_token từ:

1. **Request đã được middleware merge** (Ưu tiên cao nhất)
```php
$request->get('cart_token')  // Lấy từ request đã merge
```

2. **Cookie** (Fallback)
```php
$request->cookie('cart_token')
```

3. **Header** (Fallback)
```php
$request->header('X-Cart-Token')
```

4. **Request Body** (Fallback, cho POST có body)
```php
$request->input('cart_token')
```

**Lưu ý quan trọng:**
- Tất cả cart APIs đều dùng POST method, nên có thể gửi body
- `$request->get('cart_token')` hoạt động với mọi HTTP method vì lấy từ request attributes
- `$request->input('cart_token')` hoạt động với POST có body

---

## 5. Luồng xử lý khi tạo đơn hàng

```
Frontend                          Backend
     |                                |
     | POST /api/order/create         |
     | (có cart_token)                |
     |------------------------------->|
     |                                | 1. Lấy cart từ Redis
     |                                |    cart:guest:{cart_token}
     |                                |
     |                                | 2. Re-validate từ database:
     |                                |    - Product tồn tại?
     |                                |    - Tồn kho đủ?
     |                                |    - Giá hiện tại?
     |                                |
     |                                | 3. Tạo order trong database
     |                                |
     |                                | 4. Xóa cart khỏi Redis
     |                                |    Cache::forget(cartKey)
     |                                |
     |                                | 5. Xóa cookie cart_token
     |                                |    (trong response)
     |                                |
     | Response với order details     |
     |<------------------------------|
     |                                |
     | Clear cart_token từ FE        |
     |                                |
```

---

## 6. Best Practices cho Frontend

### 1. Luôn gửi cart_token trong mọi request
```javascript
// Helper function
const getCartToken = () => {
  // Ưu tiên: cookie > localStorage > null
  const cookieToken = getCookie('cart_token');
  if (cookieToken) return cookieToken;
  
  const localToken = localStorage.getItem('cart_token');
  if (localToken) return localToken;
  
  return null;
};

// Sử dụng
const token = getCartToken();
fetch('/api/cart', {
  headers: {
    'X-Cart-Token': token, // Optional nhưng tốt
  },
  credentials: 'include', // Quan trọng!
});
```

### 2. Sync cart_token sau mỗi response
```javascript
const syncCartToken = (response) => {
  // Đọc từ Set-Cookie header
  const setCookie = response.headers.get('Set-Cookie');
  if (setCookie && setCookie.includes('cart_token=')) {
    const match = setCookie.match(/cart_token=([^;]+)/);
    if (match) {
      const token = match[1];
      localStorage.setItem('cart_token', token);
      setCartToken(token); // React state
    }
  }
};
```

### 3. Xử lý khi cart_token bị mất
```javascript
const fetchCart = async () => {
  try {
    const response = await fetch('/api/cart', {
      credentials: 'include',
    });
    
    if (response.ok) {
      const data = await response.json();
      // Backend tự động tạo cart_token mới nếu chưa có
      syncCartToken(response);
      return data;
    }
  } catch (error) {
    // Retry với localStorage token
    const backupToken = localStorage.getItem('cart_token');
    if (backupToken) {
      return fetch('/api/cart', {
        headers: { 'X-Cart-Token': backupToken },
        credentials: 'include',
      });
    }
  }
};
```

---

## 7. Security & Performance

### Security
- ✅ Không lưu giá trong cache (tránh manipulation)
- ✅ Validate product từ database mỗi lần
- ✅ Kiểm tra tồn kho trước khi tạo order
- ✅ TTL tự động xóa cart cũ (3 ngày)

### Performance
- ✅ Redis nhanh hơn database
- ✅ Chỉ lưu ID, không lưu full data
- ✅ Load product details khi cần (lazy loading)

---

## 8. Troubleshooting

### Vấn đề: cart_token bị mất sau khi reload

**Nguyên nhân:**
- Cookie không được set đúng domain
- Browser chặn third-party cookies

**Giải pháp:**
```javascript
// Lưu backup vào localStorage
const token = getCookie('cart_token');
if (token) {
  localStorage.setItem('cart_token', token);
}

// Khi reload, thử dùng backup
const backupToken = localStorage.getItem('cart_token');
if (backupToken) {
  // Gửi trong header hoặc body
  fetch('/api/cart', {
    headers: { 'X-Cart-Token': backupToken },
  });
}
```

### Vấn đề: Cart bị mất sau 3 ngày

**Đây là behavior mong muốn:**
- TTL 3 ngày để cleanup cart cũ
- User có thể add lại sản phẩm
- Hoặc tạo order trước khi hết hạn

---

## 9. Tóm tắt

1. **Cart Token được tạo ở đâu?**
   - Backend (CartTokenMiddleware) tự động tạo UUID nếu chưa có

2. **Cart Token được lưu ở đâu?**
   - Cookie (browser tự động quản lý)
   - Redis (backend lưu cart data)
   - localStorage (frontend backup, optional)

3. **Cart Token được gửi như thế nào?**
   - **Cookie** (tự động, ưu tiên cao nhất) - Browser tự động gửi
   - **Header** `X-Cart-Token` (optional, fallback)
   - **Body** `cart_token` (optional, cho POST có body)

4. **Controller lấy cart_token như thế nào?**
   - Từ request đã được middleware merge: `$request->get('cart_token')` (ưu tiên cao nhất)
   - Từ cookie: `$request->cookie('cart_token')` (fallback)
   - Từ header: `$request->header('X-Cart-Token')` (fallback)
   - Từ body: `$request->input('cart_token')` (fallback, cho POST có body)

4. **Khi nào cart_token bị xóa?**
   - Sau khi tạo order thành công
   - Sau 3 ngày không sử dụng (TTL)
   - User clear cookies

5. **Frontend cần làm gì?**
   - Luôn gửi `credentials: 'include'` để gửi cookie
   - Không cần gửi `cart_token` trong body cho GET request
   - Backup cart_token vào localStorage (optional)
   - Sync cart_token sau mỗi response (optional)

6. **POST /api/cart:**
   - POST có body, có thể gửi `cart_token` trong body (optional)
   - `cart_token` ưu tiên từ cookie → header → body
   - Middleware tự động tạo và set cookie nếu chưa có
   - Controller lấy từ request (đã merge) → cookie → header → body
   - Frontend có thể gửi body rỗng `{}` hoặc không gửi body

