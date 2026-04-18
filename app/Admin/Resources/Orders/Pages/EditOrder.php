<?php

namespace App\Admin\Resources\Orders\Pages;

use App\Admin\Resources\Orders;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderProcess;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = Orders::class;

    protected function getHeaderActions(): array
    {
        return [
            // 确认付款：WAIT_PAY → PROCESSING（人工确认线下付款）
            Action::make('confirm_payment')
                ->label('确认付款')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === Order::STATUS_WAIT_PAY)
                ->action(function () {
                    app(OrderProcess::class)->completedOrder($this->record->order_sn, (float)$this->record->actual_price);
                    $this->record->refresh();
                    Notification::make()->title('已确认付款，订单进入处理流程')->success()->send();
                    $this->refreshFormData(['status']);
                }),

            // 标记完成：PENDING/PROCESSING → COMPLETED（人工发货后标记）
            Action::make('mark_completed')
                ->label('标记完成')
                ->color('primary')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->visible(fn () => in_array($this->record->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING]))
                ->action(function () {
                    $this->record->status = Order::STATUS_COMPLETED;
                    $this->record->save();
                    Notification::make()->title('订单已标记为完成')->success()->send();
                    $this->refreshFormData(['status']);
                }),

            // 标记失败：PENDING/PROCESSING/ABNORMAL → FAILURE
            Action::make('mark_failure')
                ->label('标记失败')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                ->visible(fn () => in_array($this->record->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_ABNORMAL]))
                ->action(function () {
                    $order = $this->record;
                    $prevStatus = $order->status;
                    $order->status = Order::STATUS_FAILURE;
                    $order->save();

                    // 若之前已累计消费（PENDING/PROCESSING），回滚 total_spent
                    if (in_array($prevStatus, [Order::STATUS_PENDING, Order::STATUS_PROCESSING]) && $order->user_id) {
                        $user = User::find($order->user_id);
                        if ($user) {
                            $user->subtractTotalSpent($order->total_price);
                        }
                    }

                    Notification::make()->title('订单已标记为失败')->warning()->send();
                    $this->refreshFormData(['status']);
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
