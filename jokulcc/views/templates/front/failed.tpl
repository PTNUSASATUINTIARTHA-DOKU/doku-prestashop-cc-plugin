{extends file='customer/page.tpl'}

{block name='page_title'}
    {l s='Checkout Failed' d='Shop.Theme.Customeraccount'}
{/block}

{block name='page_content_container' prepend}
    <section id="content-hook_order_confirmation" class="card mb-3">
        <div class="card-body">
            <br />
            <p style="margin: 35px">
                {l s='Your order on' mod='jokulcc'} <b>{$shop.name}</b> <b>{l s='is FAILED.'}</b>
                <br />
                {l s='Please review your information and try again.' mod='jokulcc'}
                <br />
                {l s='You have chosen'} <b>{$payment_channel}</b> {l s='Payment Channel Method via' mod='jokulcc'} <b>{l s='Jokul - Credit Card' mod='jokulcc'}</b>
                <br />
                <br />
                <b>{l s='For any questions or for further information, please contact our' mod='jokulcc'} <a href="{$urls.pages.contact}">{l s='customer support' mod='jokulcc'}</a>.</b>
            </p>
            <br />
        </div>
    </section>
{/block}