Link do site no render:
https://projeto-pi-2-semestre.onrender.com 

Recuperação de senha:
- Configure no Render `APP_URL=https://projeto-pi-2-semestre.onrender.com`, `RESEND_API_KEY` (chave da API do Resend) e `MAIL_FROM` (remetente verificado no Resend, por exemplo `Um Pé de IDEIA <aspi@umpedeideia.com>`).
- O banco precisa permitir que a aplicação crie a tabela `recuperacao_senha`; ela é criada automaticamente no primeiro pedido ou uso de redefinição.
- Links de redefinição expiram em 30 minutos e só podem ser usados uma vez.
