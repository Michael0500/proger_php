<?php

namespace app\models;

use Yii;
use yii\base\Model;

/**
 * Форма обратной связи базового Yii-приложения.
 *
 * Используется для отправки сообщения на заданный email через настроенный
 * mailer. Не участвует в бизнес-процессе выверки.
 */
class ContactForm extends Model
{
    public $name;
    public $email;
    public $subject;
    public $body;
    public $verifyCode;


    /**
     * Возвращает правила валидации формы обратной связи.
     *
     * @return array Правила Yii Validator.
     */
    public function rules()
    {
        return [
            // name, email, subject and body are required
            [['name', 'email', 'subject', 'body'], 'required'],
            // email has to be a valid email address
            ['email', 'email'],
            // verifyCode needs to be entered correctly
            ['verifyCode', 'captcha'],
        ];
    }

    /**
     * Возвращает подписи атрибутов формы.
     *
     * @return array Массив `attribute => label`.
     */
    public function attributeLabels()
    {
        return [
            'verifyCode' => 'Verification Code',
        ];
    }

    /**
     * Отправляет сообщение формы на указанный адрес.
     *
     * Побочный эффект: при успешной валидации вызывает mailer и отправляет
     * письмо от имени системного отправителя с reply-to пользователя.
     *
     * @param string $email Email получателя сообщения.
     * @return bool `true`, если форма валидна и отправка была инициирована.
     */
    public function contact($email)
    {
        if ($this->validate()) {
            Yii::$app->mailer->compose()
                ->setTo($email)
                ->setFrom([Yii::$app->params['senderEmail'] => Yii::$app->params['senderName']])
                ->setReplyTo([$this->email => $this->name])
                ->setSubject($this->subject)
                ->setTextBody($this->body)
                ->send();

            return true;
        }
        return false;
    }
}
