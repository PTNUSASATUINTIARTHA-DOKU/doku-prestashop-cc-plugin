<br />
<br />
<p>
    {l s='Your order on' mod='jokulcc'} <b>{$shop.name}</b> {l s='is WAITING FOR PAYMENT.' mod='jokulcc'}
	  <br />
	  {l s='You have chosen'} <b>{$payment_channel}</b> {l s='Payment Channel Method via' mod='jokulcc'} <b></b>{l s='DOKU' mod='jokulcc'}</b>
		<br />
		{l s='This is your Payment Code : ' mod='jokulcc'} <b>{$payment_code}</b> {l s='Please do the payment immediately' mod='jokulcc'}
    <br />
    <br />
    <b>{l s='After we receive your payment, we will process your order.' mod='jokulcc'}</b>
    <br />
    <br />
    <b>{l s='For any questions or for further information, please contact our' mod='jokulcc'} <a href="{$urls.pages.contact}">{l s='customer support' mod='jokulcc'}</a>.</b>
    
</p>