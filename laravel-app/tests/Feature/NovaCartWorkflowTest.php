<?php

namespace Tests\Feature;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NovaCartWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_novacart_user_journey(): void
    {
        // 1. تسجيل زبون جديد مع مسافات في حقل username
        $registerResponse = $this->postJson('/api/v1/register', [
            'username' => 'Mohammed Alrifai',
            'email' => 'mohammed@novacart.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $registerResponse->assertStatus(201)
            ->assertJsonStructure(['message', 'token', 'data']);

        // 2. تسجيل الدخول والحصول على الـ Bearer Token
        $loginResponse = $this->postJson('/api/v1/login', [
            'email' => 'mohammed@novacart.com',
            'password' => 'Password123!',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonStructure(['message', 'token', 'data']);

        $token = $loginResponse->json('token');

        // 3. التحقق من مسار جلب البيانات الشخصية المباشر (/api/me) باستخدام الـ Token
        $meResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/me');

        $meResponse->assertStatus(200)
            ->assertJsonPath('data.email', 'mohammed@novacart.com');

        // 4. إنشاء فئة ومنتج تجريبي لاختبار واجهة المتجر الرئيسية (/api)
        $category = Category::create(['name' => 'Electronics']);
        $product = Product::create([
            'title' => 'Gaming Laptop',
            'description' => 'High performance laptop',
            'price' => 1200.00,
            'stock_quantity' => 10,
            'category_id' => $category->id,
        ]);

        // 5. اختبار تصفح المنتجات في المتجر الرئيسي (/api)
        $shopResponse = $this->getJson('/api/v1');
        $shopResponse->assertStatus(200)
            ->assertJsonFragment(['title' => 'Gaming Laptop']);

        // 6. اختبار عرض تفاصيل منتج معين (/api/products/{product})
        $productShowResponse = $this->getJson('/api/v1/products/'.$product->id);
        $productShowResponse->assertStatus(200)
            ->assertJsonFragment(['id' => $product->id]);
    }
}
