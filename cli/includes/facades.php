<?php

class TaxiFacade extends Facade
{
    public static function containerKey(): string
    {
        return 'RichardStyles\\Taxi\\' . basename(str_replace('\\', '/', get_called_class()));
    }
}

class Taxi extends TaxiFacade
{
    //
}

class Config extends TaxiFacade
{
    //
}