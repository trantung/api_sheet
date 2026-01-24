# Cart API Documentation

API giỏ hàng cho guest users (không cần login). Sử dụng `cart_token` (UUID) để nhận diện giỏ hàng.

## Base URL
```
https://api.sheet.microgem.io.vn/api
```

## Authentication
Tất cả các API cart đều sử dụng `cart_token`:
- **Cookie**: `cart_token` (tự động set bởi middleware nếu chưa có, ưu tiên cao nhất)
- **Header**: `X-Cart-Token` (optional, fallback)
- **Body**: `cart_token` (optional, chỉ cho POST/PUT/DELETE có body)

**Luồng xử lý:**
1. Middleware kiểm tra `cart_token` từ cookie → header → body (theo thứ tự)
2. Nếu chưa có hoặc không hợp lệ → tự động tạo UUID mới
3. Middleware merge `cart_token` vào request
4. Controller lấy `cart_token` từ request (đã được middleware xử lý) hoặc cookie/header/body
5. Middleware set cookie trong response nếu chưa có

**Lưu ý quan trọng:**
- GET request không có body, nên `cart_token` phải từ cookie hoặc header
- Browser tự động gửi cookie trong mọi request (nếu có)
- Frontend nên dùng `credentials: 'include'` để đảm bảo cookie được gửi

---

## 1. POST /api/cart - Lấy giỏ hàng

Lấy thông tin giỏ hàng với đầy đủ chi tiết sản phẩm.

**Lưu ý:** `cart_token` được lấy từ:
- Cookie (tự động gửi bởi browser, ưu tiên cao nhất)
- Header `X-Cart-Token` (optional)
- Body `cart_token` (optional)
- Middleware tự động tạo nếu chưa có

### Request (lần đầu - chưa có cookie)
```bash
curl --location 'https://api.sheet.microgem.io.vn/api/cart' \
  --header 'Accept: application/json, text/plain, */*' \
  --header 'Accept-Language: vi,en-US;q=0.9,en;q=0.8' \
  --header 'Connection: keep-alive' \
  --header 'Origin: https://testshop3.microgem.io.vn' \
  --header 'Referer: https://testshop3.microgem.io.vn/' \
  --header 'Sec-Fetch-Dest: empty' \
  --header 'Sec-Fetch-Mode: cors' \
  --header 'Sec-Fetch-Site: cross-site' \
  --header 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' \
  --header 'accept: application/json' \
  --header 'content-type: application/json' \
  --data '{}'
```

**Lưu ý:** 
- Không cần gửi `cart_token` trong request lần đầu (có thể gửi body rỗng `{}`)
- Middleware sẽ tự động tạo UUID mới và set cookie trong response
- Response sẽ có header `Set-Cookie: cart_token=...`

### Request (có cookie - khuyến nghị)
```bash
curl --location 'https://api.sheet.microgem.io.vn/api/cart' \
  --header 'Accept: application/json, text/plain, */*' \
  --header 'Accept-Language: vi,en-US;q=0.9,en;q=0.8' \
  --header 'Connection: keep-alive' \
  --header 'Origin: https://testshop3.microgem.io.vn' \
  --header 'Referer: https://testshop3.microgem.io.vn/' \
  --header 'Sec-Fetch-Dest: empty' \
  --header 'Sec-Fetch-Mode: cors' \
  --header 'Sec-Fetch-Site: cross-site' \
  --header 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' \
  --header 'accept: application/json' \
  --header 'content-type: application/json' \
  --header 'Cookie: cart_token=550e8400-e29b-41d4-a716-446655440000' \
  --data '{}'
```

### Request (với cart_token trong body - alternative)
```bash
curl --location 'https://api.sheet.microgem.io.vn/api/cart' \
  --header 'Accept: application/json, text/plain, */*' \
  --header 'Origin: https://testshop3.microgem.io.vn' \
  --header 'accept: application/json' \
  --header 'content-type: application/json' \
  --data '{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000"
}'
```

### Request (với header X-Cart-Token - alternative)
```bash
curl --location 'https://api.sheet.microgem.io.vn/api/cart' \
  --header 'Accept: application/json, text/plain, */*' \
  --header 'Origin: https://testshop3.microgem.io.vn' \
  --header 'accept: application/json' \
  --header 'content-type: application/json' \
  --header 'X-Cart-Token: 550e8400-e29b-41d4-a716-446655440000' \
  --data '{}'
```

### Response
```json
{
    "success": true,
    "status": 200,
    "message": null,
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

---

## 2. POST /api/cart/add - Thêm sản phẩm vào giỏ hàng

Thêm sản phẩm vào giỏ hàng. Nếu sản phẩm đã tồn tại (cùng product_id và variant_id), sẽ cộng thêm số lượng.

### Request
```bash
curl --location 'https://api.sheet.microgem.io.vn/api/cart/add' \
  --header 'Accept: application/json, text/plain, */*' \
  --header 'Accept-Language: vi,en-US;q=0.9,en;q=0.8' \
  --header 'Connection: keep-alive' \
  --header 'Origin: https://testshop3.microgem.io.vn' \
  --header 'Referer: https://testshop3.microgem.io.vn/' \
  --header 'Sec-Fetch-Dest: empty' \
  --header 'Sec-Fetch-Mode: cors' \
  --header 'Sec-Fetch-Site: cross-site' \
  --header 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' \
  --header 'accept: application/json' \
  --header 'content-type: application/json' \
  --header 'Cookie: cart_token=550e8400-e29b-41d4-a716-446655440000' \
  --data '{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000",
    "product_id": 1,
    "variant_id": null,
    "qty": 2
}'
```

### Request Body
```json
{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000",
    "product_id": 1,
    "variant_id": null,
    "qty": 2
}
```

### Response
```json
{
    "success": true,
    "status": 200,
    "message": null,
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

### Validation Rules
- `product_id`: required, integer
- `variant_id`: nullable, integer
- `qty`: required, integer, min:1

---

## 3. POST /api/cart/update - Cập nhật số lượng sản phẩm

Cập nhật số lượng sản phẩm trong giỏ hàng. Nếu `qty = 0`, sản phẩm sẽ bị xóa khỏi giỏ hàng.

### Request
```bash
curl --location 'https://api.sheet.microgem.io.vn/api/cart/update' \
  --header 'Accept: application/json, text/plain, */*' \
  --header 'Accept-Language: vi,en-US;q=0.9,en;q=0.8' \
  --header 'Connection: keep-alive' \
  --header 'Origin: https://testshop3.microgem.io.vn' \
  --header 'Referer: https://testshop3.microgem.io.vn/' \
  --header 'Sec-Fetch-Dest: empty' \
  --header 'Sec-Fetch-Mode: cors' \
  --header 'Sec-Fetch-Site: cross-site' \
  --header 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' \
  --header 'accept: application/json' \
  --header 'content-type: application/json' \
  --header 'Cookie: cart_token=550e8400-e29b-41d4-a716-446655440000' \
  --data '{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000",
    "product_id": 1,
    "variant_id": null,
    "qty": 5
}'
```

### Request Body
```json
{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000",
    "product_id": 1,
    "variant_id": null,
    "qty": 5
}
```

### Response
```json
{
    "success": true,
    "status": 200,
    "message": null,
    "data": {
        "items": [
            {
                "product_id": 1,
                "variant_id": null,
                "sku": "789-03",
                "name": "Tungalso1",
                "price": "200.33",
                "quantity": 5,
                "thumbnail": "https://ik.imagekit.io/sheets/Tungalso1.png"
            }
        ],
        "subtotal": 1001.65,
        "count": 1,
        "updated_at": "2026-01-24T11:44:16+07:00"
    }
}
```

### Validation Rules
- `product_id`: required, integer
- `variant_id`: nullable, integer
- `qty`: required, integer, min:0

---

## 4. POST /api/cart/remove - Xóa sản phẩm khỏi giỏ hàng

Xóa một sản phẩm cụ thể khỏi giỏ hàng.

### Request
```bash
curl --location 'https://api.sheet.microgem.io.vn/api/cart/remove' \
  --header 'Accept: application/json, text/plain, */*' \
  --header 'Accept-Language: vi,en-US;q=0.9,en;q=0.8' \
  --header 'Connection: keep-alive' \
  --header 'Origin: https://testshop3.microgem.io.vn' \
  --header 'Referer: https://testshop3.microgem.io.vn/' \
  --header 'Sec-Fetch-Dest: empty' \
  --header 'Sec-Fetch-Mode: cors' \
  --header 'Sec-Fetch-Site: cross-site' \
  --header 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' \
  --header 'accept: application/json' \
  --header 'content-type: application/json' \
  --header 'Cookie: cart_token=550e8400-e29b-41d4-a716-446655440000' \
  --data '{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000",
    "product_id": 1,
    "variant_id": null
}'
```

### Request Body
```json
{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000",
    "product_id": 1,
    "variant_id": null
}
```

### Response
```json
{
    "success": true,
    "status": 200,
    "message": null,
    "data": {
        "items": [],
        "subtotal": 0,
        "count": 0,
        "updated_at": "2026-01-24T11:44:16+07:00"
    }
}
```

### Validation Rules
- `product_id`: required, integer
- `variant_id`: nullable, integer

---

## 5. POST /api/cart/clear - Xóa toàn bộ giỏ hàng

Xóa tất cả sản phẩm trong giỏ hàng.

### Request
```bash
curl --location 'https://api.sheet.microgem.io.vn/api/cart/clear' \
  --header 'Accept: application/json, text/plain, */*' \
  --header 'Accept-Language: vi,en-US;q=0.9,en;q=0.8' \
  --header 'Connection: keep-alive' \
  --header 'Origin: https://testshop3.microgem.io.vn' \
  --header 'Referer: https://testshop3.microgem.io.vn/' \
  --header 'Sec-Fetch-Dest: empty' \
  --header 'Sec-Fetch-Mode: cors' \
  --header 'Sec-Fetch-Site: cross-site' \
  --header 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' \
  --header 'accept: application/json' \
  --header 'Cookie: cart_token=550e8400-e29b-41d4-a716-446655440000' \
  --data '{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000"
}'
```

### Request Body
```json
{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000"
}
```

### Response
```json
{
    "success": true,
    "status": 200,
    "message": "Cart cleared successfully",
    "data": null
}
```

---

## 6. POST /api/order/create - Tạo đơn hàng từ giỏ hàng

Tạo đơn hàng từ giỏ hàng hiện tại. Sau khi tạo đơn hàng thành công, giỏ hàng sẽ bị xóa.

### Request
```bash
curl --location 'https://api.sheet.microgem.io.vn/api/order/create' \
  --header 'Accept: application/json, text/plain, */*' \
  --header 'Accept-Language: vi,en-US;q=0.9,en;q=0.8' \
  --header 'Connection: keep-alive' \
  --header 'Origin: https://testshop3.microgem.io.vn' \
  --header 'Referer: https://testshop3.microgem.io.vn/' \
  --header 'Sec-Fetch-Dest: empty' \
  --header 'Sec-Fetch-Mode: cors' \
  --header 'Sec-Fetch-Site: cross-site' \
  --header 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' \
  --header 'accept: application/json' \
  --header 'content-type: application/json' \
  --header 'Cookie: cart_token=550e8400-e29b-41d4-a716-446655440000' \
  --data '{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000",
    "name": "PHAM NGOC KIEN",
    "email": "kienp2901@gmail.com",
    "phone": "0123456789",
    "note": "",
    "address": "Pháp Vân, Hoàng Mai, Hà Nội",
    "currency": "$",
    "shipping": 30,
    "discount": 0,
    "discount_coupon": "",
    "method": "COD"
}'
```

### Request Body
```json
{
    "cart_token": "550e8400-e29b-41d4-a716-446655440000",
    "name": "PHAM NGOC KIEN",
    "email": "kienp2901@gmail.com",
    "phone": "0123456789",
    "note": "",
    "address": "Pháp Vân, Hoàng Mai, Hà Nội",
    "currency": "$",
    "shipping": 30,
    "discount": 0,
    "discount_coupon": "",
    "method": "COD"
}
```

### Response
```json
{
    "success": true,
    "status": 200,
    "message": null,
    "data": {
        "order_no": "05267912",
        "name": "PHAM NGOC KIEN",
        "email": "kienp2901@gmail.com",
        "phone": "0123456789",
        "note": "",
        "address": "Pháp Vân, Hoàng Mai, Hà Nội",
        "discount_coupon": "",
        "currency": "$",
        "discount": 0,
        "subtotal": 400.66,
        "shipping": 30,
        "total": 430.66,
        "method": "COD",
        "status": 0,
        "products": [
            {
                "sku": "789-03",
                "name": "Tungalso1",
                "price": "200.33",
                "quantity": 2,
                "id": 1
            }
        ],
        "created_at": "2026-01-24 11:44:16",
        "updated_at": "2026-01-24 11:44:16",
        "id": 1
    }
}
```

### Validation Rules
- `cart_token`: required
- `name`: required, string
- `email`: required, email
- `address`: required, string
- `phone`: nullable, string
- `note`: nullable, string
- `currency`: sometimes, string
- `shipping`: sometimes, numeric
- `discount`: sometimes, numeric
- `discount_coupon`: nullable, string
- `method`: sometimes, string

### Lưu ý
- Backend sẽ re-validate tất cả sản phẩm từ database
- Kiểm tra tồn kho trước khi tạo đơn hàng
- Sử dụng giá hiện tại từ database (không tin giá từ cache)
- Sau khi tạo đơn hàng thành công, giỏ hàng sẽ bị xóa

---

## Error Responses

### 400 Bad Request
```json
{
    "success": false,
    "status": 400,
    "message": "Domain not found",
    "data": null
}
```

### 404 Not Found
```json
{
    "success": false,
    "status": 404,
    "message": "Product not found",
    "data": null
}
```

---

## Notes

1. **Cart Token**: 
   - Tự động tạo UUID nếu request chưa có (bởi CartTokenMiddleware)
   - Lưu trong cookie `cart_token` (httpOnly = false, TTL 3 ngày)
   - Có thể gửi qua header `X-Cart-Token` hoặc body `cart_token` (fallback)
   - Controller lấy từ nhiều nguồn: request (middleware merge) → cookie → header → body

2. **Luồng xử lý cart_token**:
   - **Middleware**: Kiểm tra cookie → header → body → tạo mới nếu chưa có → merge vào request → set cookie
   - **Controller**: Lấy từ request (đã merge) → cookie → header → body
   - **POST /api/cart**: Có body, có thể gửi `cart_token` trong body (optional), nhưng ưu tiên cookie

3. **Redis Storage**:
   - Key format: `cart:guest:{cart_token}`
   - TTL: 3 ngày (tự động reset khi update)
   - Chỉ lưu: `product_id`, `variant_id`, `qty`
   - Không lưu giá trong cache

4. **Domain Detection**:
   - Tự động lấy từ header `Origin`
   - Normalize domain (lowercase, remove www)

5. **Product Validation**:
   - Tất cả sản phẩm được validate từ database
   - Giá được lấy từ database mỗi lần get cart
   - Thumbnail được lấy từ field `images` (JSON array)

6. **Frontend Best Practices**:
   - Luôn dùng `credentials: 'include'` để gửi cookie
   - Có thể gửi body rỗng `{}` hoặc không gửi body cho POST /api/cart
   - Có thể gửi `cart_token` trong body (optional, cookie vẫn ưu tiên hơn)
   - Có thể backup `cart_token` vào localStorage để phòng khi cookie bị mất

