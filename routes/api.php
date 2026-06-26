<?php

use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\AdminTraceController;
use App\Http\Controllers\AuthSessionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\SupplierPoController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthSessionController::class, 'store']);
Route::post('/token/refresh', [AuthSessionController::class, 'refresh']);
Route::post('/logout', [AuthSessionController::class, 'destroy']);

Route::middleware('jwt.auth')->group(function (): void {
    Route::get('/me', [AuthSessionController::class, 'show']);
    Route::put('/password', [AuthSessionController::class, 'updatePassword']);
    Route::get('/dashboard', [DashboardController::class, 'show']);
    Route::get('/notifications', [NotificationController::class, 'index']);

    Route::get('/quotations', [QuotationController::class, 'index']);
    Route::get('/quotations/create-options', [QuotationController::class, 'createOptions']);
    Route::get('/quotations/{quotation}', [QuotationController::class, 'show']);
    Route::post('/quotations', [QuotationController::class, 'store']);
    Route::put('/quotations/{quotation}', [QuotationController::class, 'update']);
    Route::post('/quotations/{quotation}/items', [QuotationController::class, 'storeItems']);
    Route::post('/quotations/{quotation}/terms', [QuotationController::class, 'storeTerms']);
    Route::post('/quotations/{quotation}/finalize', [QuotationController::class, 'finalize']);
    Route::post('/quotations/{quotation}/buyer-po', [QuotationController::class, 'storeBuyerPo']);
    Route::get('/quotations/{quotation}/versions/{versionNumber}/download/{format}', [QuotationController::class, 'downloadVersion']);

    Route::get('/supplier-pos', [SupplierPoController::class, 'index']);
    Route::get('/supplier-pos/create-options', [SupplierPoController::class, 'createOptions']);
    Route::post('/supplier-pos', [SupplierPoController::class, 'store']);
    Route::get('/supplier-pos/{supplierPo}', [SupplierPoController::class, 'show']);
    Route::put('/supplier-pos/{supplierPo}', [SupplierPoController::class, 'update']);
    Route::get('/supplier-pos/{supplierPo}/download/{format}', [SupplierPoController::class, 'download']);

    Route::get('/follow-up', [FollowUpController::class, 'index']);
    Route::get('/follow-up/quotations', [FollowUpController::class, 'quotationIndex']);
    Route::get('/follow-up/quotations/{quotation}', [FollowUpController::class, 'quotationShow']);
    Route::post('/follow-up/quotations/{quotation}/groups', [FollowUpController::class, 'storeQuotationGroup']);
    Route::delete('/follow-up/quotations/{quotation}/groups/{groupKey}', [FollowUpController::class, 'splitQuotationGroup']);
    Route::get('/follow-up/{followUpItem}', [FollowUpController::class, 'show']);
    Route::put('/follow-up/{followUpItem}/reminder', [FollowUpController::class, 'updateReminder']);
    Route::post('/follow-up/{followUpItem}/comments', [FollowUpController::class, 'storeComment']);
    Route::post('/follow-up/{followUpItem}/acknowledgement', [FollowUpController::class, 'acknowledge']);
    Route::get('/follow-up/{followUpItem}/shipping-documents', [FollowUpController::class, 'shippingDocuments']);
    Route::post('/follow-up/{followUpItem}/shipping-documents/complete', [FollowUpController::class, 'completeShippingDocuments']);
    Route::post('/follow-up/{followUpItem}/shipping-documents/{documentType}', [FollowUpController::class, 'uploadShippingDocument']);
    Route::post('/follow-up/{followUpItem}/packing-list', [FollowUpController::class, 'storePackingList']);
    Route::get('/follow-up/{followUpItem}/logistics', [FollowUpController::class, 'logistics']);
    Route::post('/follow-up/{followUpItem}/logistics/eta', [FollowUpController::class, 'recordEta']);
    Route::post('/follow-up/{followUpItem}/logistics/documents-sent', [FollowUpController::class, 'markDocumentsSent']);
    Route::post('/follow-up/{followUpItem}/logistics/arrived', [FollowUpController::class, 'markArrived']);
    Route::post('/follow-up/{followUpItem}/logistics/warehouse-received', [FollowUpController::class, 'markWarehouseReceived']);
    Route::post('/follow-up/{followUpItem}/logistics/buyer-received', [FollowUpController::class, 'markBuyerReceived']);
    Route::post('/follow-up/{followUpItem}/delivery-order', [FollowUpController::class, 'storeDeliveryOrder']);
    Route::post('/follow-up/{followUpItem}/delivery-order/signed', [FollowUpController::class, 'uploadSignedDeliveryOrder']);
    Route::post('/follow-up/{followUpItem}/invoice', [FollowUpController::class, 'storeInvoice']);
    Route::post('/follow-up/{followUpItem}/invoice/sent', [FollowUpController::class, 'markInvoiceSent']);
    Route::post('/follow-up/{followUpItem}/payments', [FollowUpController::class, 'storePayment']);
    Route::post('/follow-up/{followUpItem}/close', [FollowUpController::class, 'closeFollowUpItem']);
    Route::put('/follow-up/{followUpItem}/assignment', [FollowUpController::class, 'assign']);
    Route::get('/packing-lists/{packingList}/download/{format}', [FollowUpController::class, 'downloadPackingList']);
    Route::get('/delivery-orders/{deliveryOrder}/download/{format}', [FollowUpController::class, 'downloadDeliveryOrder']);
    Route::get('/invoices/{invoice}/download/{format}', [FollowUpController::class, 'downloadInvoice']);

    Route::prefix('admin')->group(function (): void {
        Route::get('/trace/quotations', [AdminTraceController::class, 'quotations']);
        Route::get('/trace/quotations/export', [AdminTraceController::class, 'exportQuotations']);
        Route::get('/trace/quotations/{quotation}', [AdminTraceController::class, 'quotation']);
        Route::get('/trace/items', [AdminTraceController::class, 'items']);
        Route::get('/trace/items/export', [AdminTraceController::class, 'exportItems']);

        Route::get('/master-data/options', [MasterDataController::class, 'options']);
        Route::get('/{resource}', [MasterDataController::class, 'index']);
        Route::post('/{resource}', [MasterDataController::class, 'store']);
        Route::put('/{resource}/{id}', [MasterDataController::class, 'update']);
        Route::delete('/{resource}/{id}', [MasterDataController::class, 'destroy']);
    });
});
