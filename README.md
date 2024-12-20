# Resumo_Pagamentos_Evolution
Esse Addon tem como Objetivo enviar Resumo de Pagamentos, pelo whatsapp, 

# Resumo

----------------------------------------------------------------------------------------------

1. Para colocar seu Token e IP ou url da API é somente ir na engrenagem no canto direito do addon que vai ter os campos designados para isso.

2. Para funcionar na TUX 4.19 precisa adicionar permissões do " apparmor "

3. Vá para o diretório /etc/apparmor.d e abra o arquivo usr.sbin.php-fpm7.3.

4. Adicione estas linha no arquivo:

        #Resumo_Pagamentos_Evolution
        /opt/mk-auth/dados/Resumo_Evolution/ rwk,
        /opt/mk-auth/dados/Resumo_Evolution/** rwk,





   


 Caso não queira reiniciar o MK-auth só dar esses dois comando abaixo.

```
sudo apparmor_parser -r /etc/apparmor.d/usr.sbin.php-fpm7.3
sudo service php7.3-fpm restart
```

