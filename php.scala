import scala.collection.immutable._;

object php{
    def echo( value:Any * ) = {
        value.foreach( print )
    }

    def array( values:Any * ):Map[Any, Any] = {
        var result = Map[Any, Any]()
        var maxIndex = -1
        values.foreach( value => {
            value match{
                case v:(Any, Any) => {
                    result += (v._1 -> v._2)
                    v._1 match {
                        case index:Int => maxIndex = maxIndex max index
                        case _ =>
                    }
                }
                case _ => {
                    maxIndex = maxIndex + 1
                    result += (maxIndex -> value)
                }
            }
        } )
        return result
    }
}

trait PHPObject{
}
