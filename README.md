# DOKU-PrestaShop-Credit Card-Plugin

Jokul makes it easy for you accept payments from various channels. Jokul also highly concerned the payment experience for your customers when they are on your store. With this plugin, you can set it up on your PrestaShop website easily and make great payment experience for your customers.

## Requirement
- PrestaShop v1.7 or higher. This plugin is tested with PrestaShop v1.7.7.3
- PHP v7.1 or higher
- MySQL v5.0 or higher
- Jokul account:
    - For testing purpose, please register to the Sandbox environment and retrieve the Client ID & Secret Key. Learn more about the sandbox environment [here](https://jokul.doku.com/docs/docs/getting-started/explore-sandbox)
    - For real transaction, please register to the Production environment and retrieve the Client ID & Secret Key. Learn more about the production registration process [here](https://jokul.doku.com/docs/docs/getting-started/register-user)

## Payment Channels Supported
Credit Card

## DOKU PrestaShop Already Supported `doku_log`
​
This `doku_log` is useful to help simplify the process of checking if an issue occurs related to the payment process using the DOKU Plugin. If there are problems or problems using the plugin, you can contact our team by sending this doku_log file. `Doku_log` will record all transaction processes from any channel by date.

​
## How to use and take doku_log file?
​
1. Open your `prestashop` directory on your store's webserver.
2. Create folder `doku_log` in your directory store's, so plugin will automatically track log in your store's webserver.
3. Then check `doku_log` and open file in your store's webserver.
4. You will see `doku log` file by date.
5. And you can download the file. 
6. If an issue occurs, you can send this `doku_log` file to the team to make it easier to find the cause of the issue.

## How to Install
1. Download the plugin from this repository.
2. Extract the plugin and compress folder "jokulcc" into zip file
3. Login to PrestaShop Admin Panel
5. Go to menu Module > Module Manager
6. Click "Upload a Module" button
7. Upload the jokulcc.zip that you have compressed
8. You are ready to setup configuration in this plugin!

## Plugin Usage

### Credit Card Configuration

1. Login to your PrestaShop Admin Panel
2. Click Module > Module Manager
3. You will find "Jokul - CC ", click "Configure" button
4. Here is the fileds that you required to set:

    ![Credit Card Configuration](https://i.ibb.co/7vC97hF/sandboxenv-com-prestashopexperiment-admin123-index-php-controller-Admin-Modules-configure-ipay88-cre.png) 

    - **Payment Method Title**: the payment channel name that will shown to the customers. You can use "O2O Convenience Store" for example
    - **Description**: the description of the payment channel that will shown to the customers. 
    - **Environment**: For testing purpose, select Sandbox. For accepting real transactions, select Production
    - **Sandbox Client ID**: Client ID you retrieved from the Sandbox environment Jokul Back Office
    - **Sandbox Shared Key**: Secret Key you retrieved from the Sandbox environment Jokul Back Office
    - **Production Client ID**: Client ID you retrieved from the Production environment Jokul Back Office
    - **Production Shared Key**: Secret Key you retrieved from the Production environment Jokul Back Office
    - **Payment Types**: Select the CC channel to wish to show to the customers. 
    - **CC Form - Background Color**: Setup the color form for the Background Credit Card form.
    - **CC Form - Label Font Color**: Setup the color form for the Label Credit Card form
    - **CC Form - Button background color**: Setup the color form the Button Background color form
    - **CC Form - Button Font Color**: Setup the color form the Button Font Color form
    - **CC Form - Languange**: Setup the languange for Credit Card form
    - **Notification URL**: Copy this URL and paste the URL into the Jokul Back Office. Learn more about how to setup Notification URL for O2O 
5. Click Save button
6. Now your customer should be able to see the payment channels and you start receiving payments


## Additional Feature

### Split Settlement Configuration

1. Login to your PrestaShop Admin Panel
2. Click Module > Module Manager
3. You will find "Jokul - CC ", click "Configure" button
4. Scroll down and you will find "List Bank Account"
5. Click icon **+** to add bank account on the list
6. And you will be redirect to Bank Account Details Configuration
![Split Settlement Configuration - add bank account](https://i.ibb.co/dG01fNc/Screen-Shot-2022-09-29-at-16-36-35.png)
7. Here is the fields that you required to set:
   - **Bank Account ID** : Bank Account Settlement ID. You can get this ID from DOKU Back Office.
   - **Type** : Choose what type that suit to your needs. Possible Option : Percentage and Fix Amount
   - **Value** : Input the number. Adjust according to the type that you had choose.
