<?php

namespace Dibs\EasyCheckout\Controller\Order;


class SaveShippingMethod extends \Dibs\EasyCheckout\Controller\Order\Update
{

    /**
     * Save shipping method action
     */

    public function execute()
    {
        if ($this->ajaxRequestAllowed()) {
            return;
        }

        $shippingMethod = $this->getRequest()->getPost('shipping_method', '');
        if (!$shippingMethod) {
            $this->getResponse()->setBody(json_encode(array('messages' => 'Please choose a valid shipping method.')));
            return;
        }

        $logContext = [
            'shipping_method' => $shippingMethod,
        ];

        if ($shippingMethod) {
            $this->dibsCheckout->getLogger()->info('Update shipping method - start', $logContext);
            try {
                $checkout = $this->getDibsCheckout();
                $checkout->updateShippingMethod($shippingMethod);
            } catch (\Magento\Framework\Exception\LocalizedException $e) {
                $this->dibsCheckout->getLogger()->error(
                    'Update shipping method error - ' . $e->getMessage(),
                    $logContext + ['trace' => (string)$e]
                );
                $this->messageManager->addExceptionMessage(
                    $e,
                    $e->getMessage()
                );
            } catch (\Exception $e) {
                $this->dibsCheckout->getLogger()->error(
                    'Update shipping method error - ' . $e->getMessage(),
                    $logContext + ['trace' => (string)$e]
                );
                $this->messageManager->addExceptionMessage(
                    $e,
                    __('We can\'t update shipping method.')
                );
            }
        }
        $this->_sendResponse(['cart', 'coupon', 'dibs_shipping_total', 'messages', 'dibs', 'newsletter', 'grand_total']);
    }

}

